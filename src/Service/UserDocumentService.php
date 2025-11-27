<?php
// src/Service/UserDocumentService.php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserDocument;
use App\Repository\UserDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Service für Dokumenten-Upload und Verwaltung
 */
class UserDocumentService
{
    // Erlaubte MIME-Types und Extensions
    private const ALLOWED_TYPES = [
        // PDFs
        'application/pdf' => ['pdf', UserDocument::TYPE_PDF],
        // Dokumente
        'application/msword' => ['doc', UserDocument::TYPE_DOCUMENT],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', UserDocument::TYPE_DOCUMENT],
        'application/vnd.oasis.opendocument.text' => ['odt', UserDocument::TYPE_DOCUMENT],
        'application/rtf' => ['rtf', UserDocument::TYPE_DOCUMENT],
        // Tabellen
        'application/vnd.ms-excel' => ['xls', UserDocument::TYPE_SPREADSHEET],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', UserDocument::TYPE_SPREADSHEET],
        'text/csv' => ['csv', UserDocument::TYPE_SPREADSHEET],
        // Bilder
        'image/jpeg' => ['jpg', UserDocument::TYPE_IMAGE],
        'image/png' => ['png', UserDocument::TYPE_IMAGE],
        'image/gif' => ['gif', UserDocument::TYPE_IMAGE],
        'image/webp' => ['webp', UserDocument::TYPE_IMAGE],
        'image/svg+xml' => ['svg', UserDocument::TYPE_IMAGE],
        // Text
        'text/plain' => ['txt', UserDocument::TYPE_TEXT],
        'text/markdown' => ['md', UserDocument::TYPE_TEXT],
        // JSON/XML
        'application/json' => ['json', UserDocument::TYPE_TEXT],
        'application/xml' => ['xml', UserDocument::TYPE_TEXT],
        'text/xml' => ['xml', UserDocument::TYPE_TEXT],
    ];

    private const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB
    private const MAX_STORAGE_PER_USER = 500 * 1024 * 1024; // 500 MB

    private Filesystem $filesystem;

    public function __construct(
        private EntityManagerInterface $em,
        private UserDocumentRepository $documentRepo,
        private LoggerInterface $logger,
        private string $uploadDirectory // Z.B. /var/uploads
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Lädt ein Dokument hoch
     */
    public function upload(
        User $user,
        UploadedFile $file,
        string $category = UserDocument::CATEGORY_OTHER,
        ?string $displayName = null,
        ?string $description = null,
        ?array $tags = null
    ): UserDocument {
        $this->logger->info('Starting document upload', [
            'userId' => $user->getId(),
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize()
        ]);
        
        // --- 1. Kritische Dateieigenschaften abrufen und cachen ---
        // Dies reduziert wiederholte Zugriffe auf die temporäre Datei, 
        // die fehlschlagen könnten (Fix für SplFileInfo::getSize() Fehler).
        $fileSize = $file->getSize();
        $mimeType = $file->getMimeType();
        $realPath = $file->getRealPath();
        
        if (!$file->isValid()) {
             throw new BadRequestHttpException('Upload fehlgeschlagen: ' . $file->getErrorMessage());
        }

        if ($fileSize === false || $fileSize === 0) {
             throw new BadRequestHttpException('Ungültige oder leere Datei hochgeladen.');
        }

        // --- 2. Validierung (verwendet gecachte Werte) ---
        
        // Dateigröße
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new BadRequestHttpException(
                sprintf('Datei zu groß. Maximum: %s', $this->formatBytes(self::MAX_FILE_SIZE))
            );
        }

        // MIME-Typ
        if (!isset(self::ALLOWED_TYPES[$mimeType])) {
            throw new BadRequestHttpException(
                sprintf('Dateityp nicht erlaubt: %s', $mimeType)
            );
        }

        // Server-seitige MIME-Validierung des Inhalts
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        if ($detectedMime !== $mimeType && !$this->areMimeTypesCompatible($mimeType, $detectedMime)) {
            throw new BadRequestHttpException('Dateiinhalt stimmt nicht mit Typ überein');
        }

        // Speicherlimit des Benutzers
        $this->validateUserStorage($user, $fileSize);

        // --- 3. Fortlaufende Verarbeitung ---

        // Duplikat-Check
        $checksum = hash_file('sha256', $realPath);
        $existingDoc = $this->documentRepo->findByUserAndChecksum($user, $checksum);
        
        if ($existingDoc) {
            throw new BadRequestHttpException(
                'Dieses Dokument existiert bereits: ' . $existingDoc->getDisplayName()
            );
        }

        // Bestimme Dokumenttyp
        $typeInfo = self::ALLOWED_TYPES[$mimeType] ?? ['unknown', UserDocument::TYPE_OTHER];
        $documentType = $typeInfo[1];

        // Generiere sicheren Dateinamen
        $storedFilename = $this->generateSecureFilename($file, $user);
        
        // User-spezifisches Verzeichnis (inkl. 'files' Unterordner)
        $userDir = $this->getUserDirectory($user);
        $this->ensureDirectory($userDir);

        // Verschiebe Datei
        $file->move($userDir, $storedFilename);
        $fullPath = $userDir . '/' . $storedFilename;

        // Erstelle Entity
        $doc = new UserDocument();
        $doc->setUser($user);
        $doc->setOriginalFilename($file->getClientOriginalName());
        $doc->setStoredFilename($storedFilename);
        $doc->setMimeType($mimeType);
        $doc->setDocumentType($documentType);
        $doc->setFileSize($fileSize); // Verwendet gecachten Wert
        $doc->setChecksum($checksum);
        $doc->setStoragePath($userDir);
        $doc->setCategory($category);
        $doc->setDisplayName($displayName ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $doc->setDescription($description);
        $doc->setTags($tags);

        // Extrahiere Metadaten
        $metadata = $this->extractMetadata($fullPath, $mimeType, $documentType);
        $doc->setMetadata($metadata);

        // Extrahiere Text (falls möglich)
        $extractedText = $this->extractText($fullPath, $mimeType, $documentType);
        if ($extractedText) {
            $doc->setExtractedText($extractedText);
        }

        $this->em->persist($doc);
        $this->em->flush();

        $this->logger->info('Document uploaded successfully', [
            'documentId' => $doc->getId(),
            'userId' => $user->getId()
        ]);

        return $doc;
    }

    /**
     * Löscht ein Dokument
     */
    public function delete(UserDocument $doc): void
    {
        $fullPath = $doc->getFullPath();

        // Lösche Datei
        if ($this->filesystem->exists($fullPath)) {
            $this->filesystem->remove($fullPath);
        }

        $this->em->remove($doc);
        $this->em->flush();

        $this->logger->info('Document deleted', ['documentId' => $doc->getId()]);
    }

    /**
     * Gibt den Inhalt eines Dokuments zurück
     */
    public function getContent(UserDocument $doc): string
    {
        $fullPath = $doc->getFullPath();

        if (!$this->filesystem->exists($fullPath)) {
            throw new \RuntimeException('Document file not found');
        }

        $doc->recordAccess();
        $this->em->flush();

        return file_get_contents($fullPath);
    }

    /**
     * Gibt den Stream eines Dokuments zurück
     */
    public function getStream(UserDocument $doc)
    {
        $fullPath = $doc->getFullPath();

        if (!$this->filesystem->exists($fullPath)) {
            throw new \RuntimeException('Document file not found');
        }

        $doc->recordAccess();
        $this->em->flush();

        return fopen($fullPath, 'rb');
    }

    /**
     * Aktualisiert Dokument-Metadaten
     */
    public function update(
        UserDocument $doc,
        ?string $displayName = null,
        ?string $description = null,
        ?string $category = null,
        ?array $tags = null
    ): UserDocument {
        if ($displayName !== null) {
            $doc->setDisplayName($displayName);
        }
        if ($description !== null) {
            $doc->setDescription($description);
        }
        if ($category !== null) {
            $doc->setCategory($category);
        }
        if ($tags !== null) {
            $doc->setTags($tags);
        }

        $doc->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $doc;
    }

    /**
     * Holt Speicherstatistiken für einen User
     */
    public function getStorageStats(User $user): array
    {
        $used = $this->documentRepo->calculateStorageUsage($user);
        $count = $this->documentRepo->countByUser($user);

        return [
            'used_bytes' => $used,
            'used_human' => $this->formatBytes($used),
            'limit_bytes' => self::MAX_STORAGE_PER_USER,
            'limit_human' => $this->formatBytes(self::MAX_STORAGE_PER_USER),
            'available_bytes' => max(0, self::MAX_STORAGE_PER_USER - $used),
            'usage_percent' => round(($used / self::MAX_STORAGE_PER_USER) * 100, 2),
            'document_count' => $count
        ];
    }

    // === Private Hilfsmethoden ===

    // Die Funktion validateFile wurde entfernt, da ihre Logik nun in upload() integriert ist.

    private function areMimeTypesCompatible(string $declared, string $detected): bool
    {
        // Einige MIME-Types können variieren
        $compatible = [
            'text/plain' => ['text/plain', 'application/octet-stream'],
            'text/csv' => ['text/plain', 'text/csv', 'application/csv'],
            'text/markdown' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        ];

        return in_array($detected, $compatible[$declared] ?? [$declared]);
    }

    private function validateUserStorage(User $user, int $additionalBytes): void
    {
        $currentUsage = $this->documentRepo->calculateStorageUsage($user);
        
        if (($currentUsage + $additionalBytes) > self::MAX_STORAGE_PER_USER) {
            throw new BadRequestHttpException(
                sprintf(
                    'Speicherlimit erreicht. Verfügbar: %s',
                    $this->formatBytes(self::MAX_STORAGE_PER_USER - $currentUsage)
                )
            );
        }
    }

    private function generateSecureFilename(UploadedFile $file, User $user): string
    {
        $extension = $file->guessExtension() ?? 'bin';
        $timestamp = (new \DateTime())->format('YmdHis');
        $random = bin2hex(random_bytes(8));
        
        return sprintf('%d_%s_%s.%s', $user->getId(), $timestamp, $random, $extension);
    }

    /**
     * Gibt das User-spezifische Upload-Verzeichnis zurück,
     * das nun einen Unterordner 'files' enthält.
     */
    private function getUserDirectory(User $user): string
    {
        // Pfad: /var/uploads/user_123/files
        return $this->uploadDirectory . '/user_' . $user->getId() . '/files';
    }

    private function ensureDirectory(string $path): void
    {
        if (!$this->filesystem->exists($path)) {
            $this->filesystem->mkdir($path, 0755);
        }
    }

    private function extractMetadata(string $path, string $mimeType, string $docType): array
    {
        $metadata = [
            'extracted_at' => (new \DateTimeImmutable())->format('c')
        ];

        if ($docType === UserDocument::TYPE_IMAGE) {
            $imageInfo = @getimagesize($path);
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['image_type'] = $imageInfo['mime'];
            }
        }

        if ($docType === UserDocument::TYPE_PDF) {
            try {
                $parser = new PdfParser();
                $pdf = $parser->parseFile($path);
                $details = $pdf->getDetails();
                
                $metadata['pages'] = $details['Pages'] ?? null;
                $metadata['author'] = $details['Author'] ?? null;
                $metadata['title'] = $details['Title'] ?? null;
                $metadata['created'] = $details['CreationDate'] ?? null;
            } catch (\Exception $e) {
                $this->logger->warning('PDF metadata extraction failed', ['error' => $e->getMessage()]);
            }
        }

        return $metadata;
    }

    private function extractText(string $path, string $mimeType, string $docType): ?string
    {
        try {
            if ($docType === UserDocument::TYPE_PDF) {
                $parser = new PdfParser();
                $pdf = $parser->parseFile($path);
                return $pdf->getText();
            }

            if ($docType === UserDocument::TYPE_TEXT) {
                return file_get_contents($path);
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->warning('Text extraction failed', [
                'error' => $e->getMessage(),
                'path' => $path
            ]);
            return null;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    /**
     * Lädt eine bereits generierte Datei hoch (z.B. von PdfGenerator)
     * Unterscheidet sich von upload() dadurch, dass die Datei bereits existiert
     * und nicht von einem UploadedFile-Objekt kommt
     */
    public function uploadGeneratedFile(
        User $user,
        \Symfony\Component\HttpFoundation\File\File $file,
        string $originalFilename,
        string $mimeType,
        string $category = UserDocument::CATEGORY_GENERATED,
        ?string $displayName = null,
        ?string $description = null,
        ?array $tags = null
    ): UserDocument {
        $this->logger->info('Starting generated file upload', [
            'userId' => $user->getId(),
            'filename' => $originalFilename,
            'category' => $category
        ]);

        // Dateigröße validieren
        $fileSize = $file->getSize();
        
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new BadRequestHttpException(
                sprintf('Datei zu groß. Maximum: %s', $this->formatBytes(self::MAX_FILE_SIZE))
            );
        }

        // Speicherlimit prüfen
        $this->validateUserStorage($user, $fileSize);

        // Checksum berechnen
        $checksum = hash_file('sha256', $file->getPathname());

        // Duplikat-Check (optional überspringen für generierte Dateien)
        $existingDoc = $this->documentRepo->findByUserAndChecksum($user, $checksum);
        if ($existingDoc) {
            $this->logger->info('Document with same checksum exists, creating new version', [
                'existing_id' => $existingDoc->getId()
            ]);
            // Bei generierten Dateien erlauben wir Duplikate (verschiedene Versionen)
        }

        // Bestimme Dokumenttyp
        $typeInfo = self::ALLOWED_TYPES[$mimeType] ?? ['unknown', UserDocument::TYPE_OTHER];
        $documentType = $typeInfo[1];

        // Generiere sicheren Dateinamen
        $storedFilename = $this->generateSecureFilenameFromOriginal($originalFilename, $user);
        
        // User-spezifisches Verzeichnis für generierte Dateien
        $userDir = $this->getGeneratedFilesDirectory($user);
        $this->ensureDirectory($userDir);

        // Kopiere Datei in finales Verzeichnis
        $targetPath = $userDir . '/' . $storedFilename;
        $this->filesystem->copy($file->getPathname(), $targetPath);

        // Erstelle Entity
        $doc = new UserDocument();
        $doc->setUser($user);
        $doc->setOriginalFilename($originalFilename);
        $doc->setStoredFilename($storedFilename);
        $doc->setMimeType($mimeType);
        $doc->setDocumentType($documentType);
        $doc->setFileSize($fileSize);
        $doc->setChecksum($checksum);
        $doc->setStoragePath($userDir);
        $doc->setCategory($category);
        $doc->setDisplayName($displayName ?? pathinfo($originalFilename, PATHINFO_FILENAME));
        $doc->setDescription($description);
        $doc->setTags($tags);

        // Extrahiere Metadaten
        $metadata = $this->extractMetadata($targetPath, $mimeType, $documentType);
        $doc->setMetadata($metadata);

        // Extrahiere Text (falls möglich)
        $extractedText = $this->extractText($targetPath, $mimeType, $documentType);
        if ($extractedText) {
            $doc->setExtractedText($extractedText);
        }

        $this->em->persist($doc);
        $this->em->flush();

        $this->logger->info('Generated file uploaded successfully', [
            'documentId' => $doc->getId(),
            'userId' => $user->getId(),
            'path' => $targetPath
        ]);

        return $doc;
    }

    /**
     * Gibt das Verzeichnis für generierte Dateien zurück
     * Pfad: /var/uploads/user_123/generated/
     */
    private function getGeneratedFilesDirectory(User $user): string
    {
        return $this->uploadDirectory . '/user_' . $user->getId() . '/generated';
    }

    /**
     * Generiert sicheren Dateinamen aus Original-Namen
     */
    private function generateSecureFilenameFromOriginal(string $originalFilename, User $user): string
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        
        // Bereinige Basename (entferne Sonderzeichen)
        $safeBasename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
        $safeBasename = substr($safeBasename, 0, 100); // Maximal 100 Zeichen
        
        $timestamp = (new \DateTime())->format('YmdHis');
        $random = bin2hex(random_bytes(4));
        
        return sprintf('%s_%s_%s.%s', $safeBasename, $timestamp, $random, $extension);
    }
}
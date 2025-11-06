<?php
namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FileSecurityService
{
    // Whitelist erlaubter MIME-Types
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];

    // Limits
    private const MAX_FILE_SIZE = 5242880;  // 5MB
    private const MIN_IMAGE_DIMENSION = 100;  // 100x100px
    private const MAX_IMAGE_DIMENSION = 10000;  // 10000x10000px

    private string $uploadDirectory;

    public function __construct(string $uploadDirectory)
    {
        $this->uploadDirectory = $uploadDirectory;
    }

    /**
     * Validiert eine hochgeladene Datei gegen Sicherheitsrichtlinien
     * 
     * @param UploadedFile $file Die zu validierende Datei
     * @throws BadRequestHttpException Bei Validierungsfehler
     */
    public function validateUploadedFile(UploadedFile $file): void
    {
        // 1. Prüfe Dateigröße
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new BadRequestHttpException(
                sprintf('Datei überschreitet das %d MB Limit', self::MAX_FILE_SIZE / 1048576)
            );
        }

        // 2. Prüfe Client-seitigen MIME-Type
        $mimeType = $file->getMimeType();
        if (!isset(self::ALLOWED_MIMES[$mimeType])) {
            throw new BadRequestHttpException(
                sprintf('Dateityp "%s" nicht erlaubt. Erlaubte Typen: %s',
                    $mimeType,
                    implode(', ', array_keys(self::ALLOWED_MIMES))
                )
            );
        }

        // 3. Prüfe mit finfo (Server-seitige Validierung)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file->getRealPath());
        finfo_close($finfo);

        if ($detectedMime !== $mimeType) {
            throw new BadRequestHttpException(
                'Dateiinhalt stimmt nicht mit dem MIME-Type überein (Manipulation verdächtig)'
            );
        }

        // 4. Validiere Bild-Struktur mit getimagesize
        $imageInfo = @getimagesize($file->getRealPath());
        if (!$imageInfo) {
            throw new BadRequestHttpException('Ungültige oder beschädigte Bilddatei');
        }

        // 5. Prüfe Bild-Dimensionen
        [$width, $height] = $imageInfo;
        if ($width < self::MIN_IMAGE_DIMENSION || $height < self::MIN_IMAGE_DIMENSION) {
            throw new BadRequestHttpException(
                sprintf('Bild muss mindestens %dx%d Pixel groß sein',
                    self::MIN_IMAGE_DIMENSION,
                    self::MIN_IMAGE_DIMENSION
                )
            );
        }

        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) {
            throw new BadRequestHttpException(
                sprintf('Bild darf maximal %dx%d Pixel groß sein',
                    self::MAX_IMAGE_DIMENSION,
                    self::MAX_IMAGE_DIMENSION
                )
            );
        }

        // 6. Prüfe auf verdächtige Dateinamen (Polyglot)
        $this->validateFilename($file->getClientOriginalName());
    }

    /**
     * Prüft auf verdächtige Dateinamen wie .php.jpg
     */
    private function validateFilename(string $filename): void
    {
        // Mehrfache Extensions nicht erlaubt
        $parts = explode('.', $filename);
        if (count($parts) > 2) {
            throw new BadRequestHttpException(
                'Dateinamen mit mehreren Extensions sind nicht erlaubt'
            );
        }

        // Prüfe auf gefährliche Kombinationen
        $dangerousPatterns = [
            '/\.php\./i',
            '/\.phtml\./i',
            '/\.exe\./i',
            '/\.sh\./i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                throw new BadRequestHttpException('Verdächtige Dateiendung erkannt');
            }
        }
    }

    /**
     * Speichert eine validierte Datei sicher mit eindeutigem Namen
     * 
     * @return string Der Dateiname (relativ zu uploadDirectory)
     */
    public function saveUploadedFile(UploadedFile $file): string
    {
        // Validierung
        $this->validateUploadedFile($file);

        // Generiere sicheren Dateinamen
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = self::ALLOWED_MIMES[$file->getMimeType()];
        
        // Sluggify Filename (nur alphanumerisch + Bindestrich)
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $originalName);
        $slug = trim($slug, '-');
        $slug = strtolower($slug);
        
        // Eindeutige ID anhängen (verhindert Kollisionen)
        $filename = sprintf('%s-%s.%s', $slug, bin2hex(random_bytes(4)), $extension);

        // Speichere in Upload-Verzeichnis
        try {
            $file->move($this->uploadDirectory, $filename);
        } catch (\Exception $e) {
            throw new BadRequestHttpException(sprintf('Upload fehlgeschlagen: %s', $e->getMessage()));
        }

        // Prüfe, dass Datei wirklich dort ist
        if (!file_exists($this->uploadDirectory . '/' . $filename)) {
            throw new BadRequestHttpException('Datei konnte nicht gespeichert werden');
        }

        return $filename;
    }

    /**
     * Löscht eine Datei sicher (mit Überschreibung)
     */
    public function secureDelete(string $filename): void
    {
        $filepath = $this->uploadDirectory . '/' . $filename;

        if (!file_exists($filepath)) {
            return;
        }

        // Path Traversal Prevention - Stelle sicher dass Pfad im Upload-Dir liegt
        if (realpath($filepath) === false || 
            strpos(realpath($filepath), realpath($this->uploadDirectory)) !== 0) {
            throw new \InvalidArgumentException('Ungültiger Dateipfad');
        }

        // Überschreibe mit Zufallsdaten (3x für sichere Löschung)
        $size = filesize($filepath);
        $handle = fopen($filepath, 'r+b');
        if ($handle) {
            for ($i = 0; $i < 3; $i++) {
                fseek($handle, 0);
                fwrite($handle, bin2hex(random_bytes($size / 2)), $size);
            }
            fclose($handle);
        }

        // Lösche Datei
        @unlink($filepath);
    }
}
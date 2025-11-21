<?php
// src/Entity/UserDocument.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserDocumentRepository;

/**
 * Hochgeladene Dokumente und Medien eines Benutzers
 * Können als Vorlagen, Anhänge oder zur Verarbeitung genutzt werden
 */
#[ORM\Entity(repositoryClass: UserDocumentRepository::class)]
#[ORM\Table(name: 'user_documents')]
#[ORM\Index(name: 'idx_user_docs', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_docs_type', columns: ['user_id', 'document_type'])]
#[ORM\Index(name: 'idx_user_docs_category', columns: ['user_id', 'category'])]
class UserDocument
{
    public const TYPE_PDF = 'pdf';
    public const TYPE_DOCUMENT = 'document'; // docx, doc, odt
    public const TYPE_SPREADSHEET = 'spreadsheet'; // xlsx, xls, csv
    public const TYPE_IMAGE = 'image';
    public const TYPE_TEXT = 'text'; // txt, md
    public const TYPE_OTHER = 'other';

    public const CATEGORY_TEMPLATE = 'template';
    public const CATEGORY_ATTACHMENT = 'attachment';
    public const CATEGORY_REFERENCE = 'reference';
    public const CATEGORY_MEDIA = 'media';
    public const CATEGORY_OTHER = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 255)]
    private string $originalFilename;

    #[ORM\Column(type: 'string', length: 255)]
    private string $storedFilename;

    #[ORM\Column(type: 'string', length: 100)]
    private string $mimeType;

    #[ORM\Column(type: 'string', length: 50)]
    private string $documentType; // pdf, document, image, etc.

    #[ORM\Column(type: 'bigint')]
    private int $fileSize;

    #[ORM\Column(type: 'string', length: 64)]
    private string $checksum; // SHA-256 Hash

    #[ORM\Column(type: 'string', length: 500)]
    private string $storagePath;

    #[ORM\Column(type: 'string', length: 50)]
    private string $category = self::CATEGORY_OTHER;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $displayName = null; // Benutzerfreundlicher Name

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null; // Zusätzliche Infos (Seitenanzahl, Dimensionen, etc.)

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $extractedText = null; // Extrahierter Text für Durchsuchbarkeit

    #[ORM\Column(type: 'boolean')]
    private bool $isIndexed = false; // In Knowledge Base indiziert?

    #[ORM\Column(type: 'boolean')]
    private bool $isPublic = false; // Öffentlich zugänglich?

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAccessedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $accessCount = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters & Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function setDocumentType(string $documentType): self
    {
        $this->documentType = $documentType;
        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function setChecksum(string $checksum): self
    {
        $this->checksum = $checksum;
        return $this;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): self
    {
        $this->storagePath = $storagePath;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getExtractedText(): ?string
    {
        return $this->extractedText;
    }

    public function setExtractedText(?string $extractedText): self
    {
        $this->extractedText = $extractedText;
        return $this;
    }

    public function isIndexed(): bool
    {
        return $this->isIndexed;
    }

    public function setIsIndexed(bool $isIndexed): self
    {
        $this->isIndexed = $isIndexed;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getLastAccessedAt(): ?\DateTimeImmutable
    {
        return $this->lastAccessedAt;
    }

    public function recordAccess(): self
    {
        $this->lastAccessedAt = new \DateTimeImmutable();
        $this->accessCount++;
        return $this;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    /**
     * Gibt den vollständigen Dateipfad zurück
     */
    public function getFullPath(): string
    {
        return $this->storagePath . '/' . $this->storedFilename;
    }

    /**
     * Gibt eine benutzerfreundliche Dateigröße zurück
     */
    public function getHumanReadableSize(): string
    {
        $bytes = $this->fileSize;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
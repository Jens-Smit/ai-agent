<?php
// src/Entity/KnowledgeDocument.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\KnowledgeDocumentRepository;

#[ORM\Entity(repositoryClass: KnowledgeDocumentRepository::class)]
#[ORM\Table(name: 'knowledge_documents')]
#[ORM\Index(name: 'idx_source', columns: ['source_file'])]
class KnowledgeDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 500)]
    private string $sourceFile;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'json')]
    private array $embedding;

    #[ORM\Column(type: 'integer')]
    private int $embeddingDimension;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getSourceFile(): string
    {
        return $this->sourceFile;
    }

    public function setSourceFile(string $sourceFile): self
    {
        $this->sourceFile = $sourceFile;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
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

    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function setEmbedding(array $embedding): self
    {
        $this->embedding = $embedding;
        $this->embeddingDimension = count($embedding);
        return $this;
    }

    public function getEmbeddingDimension(): int
    {
        return $this->embeddingDimension;
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
}
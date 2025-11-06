<?php

namespace App\Entity;

use App\Repository\AgentTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentTaskRepository::class)]
#[ORM\Table(name: 'agent_task')]
class AgentTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private ?string $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $prompt;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = 'processing'; // z.B. processing, awaiting_review, completed, failed

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $generatedCode = null; // serialized JSON of files/changes

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $generatedFiles = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $testResults = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct(string $prompt)
    {
        $this->prompt = $prompt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }
    
    // --- Getter und Setter (Auszug) ---
    
    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }

    public function setGeneratedFiles(array $generatedFiles): self
    {
        $this->generatedFiles = $generatedFiles;
        return $this;
    }

    public function getTestResults(): array
    {
        return $this->testResults;
    }

    public function setTestResults(array $testResults): self
    {
        $this->testResults = $testResults;
        return $this;
    }
    
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Fügen Sie bei Bedarf weitere Felder, Getter und Setter hinzu.
}
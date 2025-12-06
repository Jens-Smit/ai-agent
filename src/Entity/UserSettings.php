<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserSettingsRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Ignore;
#[ORM\Entity(repositoryClass: UserSettingsRepository::class)]
#[ORM\Table(name: 'user_settings')]
class UserSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'userSettings', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    #[Ignore]
    private ?User $user = null;

    // POP3 Settings
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $pop3Host = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pop3Port = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $pop3Encryption = null; // e.g., 'ssl', 'tls', 'none'

    // IMAP Settings
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $imapHost = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imapPort = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $imapEncryption = null; // e.g., 'ssl', 'tls', 'none'

    // SMTP Settings
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $smtpPort = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $smtpEncryption = null; // e.g., 'ssl', 'tls', 'none'

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $smtpUsername = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $smtpPassword = null; // Store encrypted

    // Common Credentials (for both sending and receiving)
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $emailAddress = null; // The user's actual email address for sending/receiving

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getPop3Host(): ?string
    {
        return $this->pop3Host;
    }

    public function setPop3Host(?string $pop3Host): self
    {
        $this->pop3Host = $pop3Host;
        return $this;
    }

    public function getPop3Port(): ?int
    {
        return $this->pop3Port;
    }

    public function setPop3Port(?int $pop3Port): self
    {
        $this->pop3Port = $pop3Port;
        return $this;
    }

    public function getPop3Encryption(): ?string
    {
        return $this->pop3Encryption;
    }

    public function setPop3Encryption(?string $pop3Encryption): self
    {
        $this->pop3Encryption = $pop3Encryption;
        return $this;
    }

    public function getImapHost(): ?string
    {
        return $this->imapHost;
    }

    public function setImapHost(?string $imapHost): self
    {
        $this->imapHost = $imapHost;
        return $this;
    }

    public function getImapPort(): ?int
    {
        return $this->imapPort;
    }

    public function setImapPort(?int $imapPort): self
    {
        $this->imapPort = $imapPort;
        return $this;
    }

    public function getImapEncryption(): ?string
    {
        return $this->imapEncryption;
    }

    public function setImapEncryption(?string $imapEncryption): self
    {
        $this->imapEncryption = $imapEncryption;
        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): self
    {
        $this->smtpHost = $smtpHost;
        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): self
    {
        $this->smtpPort = $smtpPort;
        return $this;
    }

    public function getSmtpEncryption(): ?string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(?string $smtpEncryption): self
    {
        $this->smtpEncryption = $smtpEncryption;
        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $smtpUsername): self
    {
        $this->smtpUsername = $smtpUsername;
        return $this;
    }

    public function getSmtpPassword(): ?string
    {
        return $this->smtpPassword;
    }

    public function setSmtpPassword(?string $smtpPassword): self
    {
        $this->smtpPassword = $smtpPassword;
        return $this;
    }

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(?string $emailAddress): self
    {
        $this->emailAddress = $emailAddress;
        return $this;
    }
}

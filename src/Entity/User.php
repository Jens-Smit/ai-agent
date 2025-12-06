<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Repository\UserRepository;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Component\Serializer\Annotation\Groups;
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    /**
     * Google OAuth Access Token
     * Wird verwendet für API-Aufrufe im Namen des Benutzers
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $googleAccessToken = null;

    /**
     * Google OAuth Refresh Token
     * Wird verwendet um neue Access Tokens zu erhalten
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $googleRefreshToken = null;

    /**
     * Ablaufzeitpunkt des Access Tokens
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $googleTokenExpiresAt = null;

    /**
     * Password Reset Token (Hash für Sicherheit)
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $resetTokenHash = null;

    /**
     * Transient: Der Klartext-Reset-Token (nur im Speicher, nicht in DB)
     */
    private ?string $resetToken = null;

    /**
     * Ablaufzeitpunkt des Reset-Tokens
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: UserSettings::class,fetch: 'LAZY' , cascade: ['persist', 'remove'])]
    private ?UserSettings $userSettings = null;

    // ==================== BASIC GETTERS/SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
          // mindestens eine Default-Rolle vergeben
       $roles = $this->roles ?? [];
        if (empty($roles)) {
            $roles[] = 'ROLE_USER';
        }
        return array_unique($roles);
        }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Lösche transiente sensitive Daten
        // Nicht: resetToken (wird manuell gelöscht)
    }

    // ==================== GOOGLE OAUTH BASIC ====================

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): self
    {
        $this->googleId = $googleId;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): self
    {
        $this->avatarUrl = $avatarUrl;
        return $this;
    }

    // ==================== GOOGLE OAUTH TOKENS ====================

    public function getGoogleAccessToken(): ?string
    {
        return $this->googleAccessToken;
    }

    public function setGoogleAccessToken(?string $googleAccessToken): self
    {
        $this->googleAccessToken = $googleAccessToken;
        return $this;
    }

    public function getGoogleRefreshToken(): ?string
    {
        return $this->googleRefreshToken;
    }

    public function setGoogleRefreshToken(?string $googleRefreshToken): self
    {
        $this->googleRefreshToken = $googleRefreshToken;
        return $this;
    }

    public function getGoogleTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->googleTokenExpiresAt;
    }

    public function setGoogleTokenExpiresAt(?\DateTimeInterface $googleTokenExpiresAt): self
    {
        $this->googleTokenExpiresAt = $googleTokenExpiresAt;
        return $this;
    }

    /**
     * Prüft ob der Google Access Token noch gültig ist
     */
    public function isGoogleAccessTokenValid(): bool
    {
        if (null === $this->googleAccessToken || null === $this->googleTokenExpiresAt) {
            return false;
        }

        // Token ist gültig wenn Ablaufzeit in der Zukunft liegt (mit 5min Buffer)
        $now = new \DateTimeImmutable();
        $bufferTime = $now->modify('+5 minutes');
        
        return $this->googleTokenExpiresAt > $bufferTime;
    }

    /**
     * Setzt Google OAuth Tokens und Ablaufzeit
     */
    public function setGoogleTokens(
        string $accessToken,
        ?string $refreshToken = null,
        ?int $expiresIn = 3600
    ): self {
        $this->googleAccessToken = $accessToken;
        
        if (null !== $refreshToken) {
            $this->googleRefreshToken = $refreshToken;
        }
        
        if (null !== $expiresIn) {
            $this->googleTokenExpiresAt = new \DateTimeImmutable("+{$expiresIn} seconds");
        }
        
        return $this;
    }

    /**
     * Löscht alle Google OAuth Tokens
     */
    public function clearGoogleTokens(): self
    {
        $this->googleAccessToken = null;
        $this->googleRefreshToken = null;
        $this->googleTokenExpiresAt = null;
        return $this;
    }

    // ==================== PASSWORD RESET TOKEN ====================

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    /**
     * Setzt einen Reset-Token (Klartext wird gehashed für DB)
     */
    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;
        
        if (null !== $resetToken) {
            // Hash für sichere Speicherung in DB
            $this->resetTokenHash = hash('sha256', $resetToken);
        } else {
            $this->resetTokenHash = null;
        }
        
        return $this;
    }

    /**
     * Setzt Reset-Token mit Ablaufzeit (Convenience-Methode)
     */
    public function setResetTokenPlain(string $token, int $ttlSeconds = 3600): self
    {
        $this->setResetToken($token);
        $this->resetTokenExpiresAt = new \DateTimeImmutable("+{$ttlSeconds} seconds");
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeInterface $resetTokenExpiresAt): self
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    /**
     * Verifiziert einen Reset-Token
     */
    public function verifyResetToken(string $token): bool
    {
        if (null === $this->resetTokenHash) {
            return false;
        }
        
        $tokenHash = hash('sha256', $token);
        return hash_equals($this->resetTokenHash, $tokenHash) && $this->isResetTokenValid();
    }

    /**
     * Prüft ob Reset-Token noch gültig ist
     */
    public function isResetTokenValid(): bool
    {
        if (null === $this->resetTokenExpiresAt) {
            return false;
        }
        
        return $this->resetTokenExpiresAt > new \DateTimeImmutable();
    }

    /**
     * Löscht Reset-Token
     */
    public function clearResetToken(): self
    {
        $this->resetToken = null;
        $this->resetTokenHash = null;
        $this->resetTokenExpiresAt = null;
        return $this;
    }

    public function getUserSettings(): ?UserSettings
    {
        return $this->userSettings;
    }

    public function setUserSettings(?UserSettings $userSettings): self
    {
        // set the owning side of the relation if necessary
        if ($userSettings !== null && $userSettings->getUser() !== $this) {
            $userSettings->setUser($this);
        }

        $this->userSettings = $userSettings;

        return $this;
    }
}

<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use DateTimeImmutable;
use OpenApi\Attributes as OA; 



#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]

class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetTokenHash = null;

    // transient (not persisted) plain token for immediate use (e.g. to email or tests)
    private ?string $resetToken = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\OneToMany(mappedBy: 'author', targetEntity: Post::class, orphanRemoval: true)]
    private Collection $posts;

   
    public function __construct()
    {
        $this->posts = new ArrayCollection();
        
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }
    
     /**
     * For tests / mailer: return the transient plain token if present.
     */
    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }
    public function setResetToken(?string $token): static   
    {
        if ($token === null) {
            $this->resetToken = null;
            $this->resetTokenHash = null;
            $this->resetTokenExpiresAt = null;
            return $this;
        }

        $this->resetToken = $token;
        $this->resetTokenHash = hash('sha256', $token);
        $this->resetTokenExpiresAt = new DateTimeImmutable('+1 hour');

        return $this;
    }
    /**
     * Set transient plain token and persist only the hash + expiry
     */
    public function setResetTokenPlain(string $token, int $ttlSeconds = 3600): static
    {
        // keep transient plain token for immediate use (not persisted)
        $this->resetToken = $token;

        // store hash for persistence
        $this->resetTokenHash = hash('sha256', $token);

        $this->resetTokenExpiresAt = new DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds));

        return $this;
    }

    public function getResetTokenHash(): ?string
    {
        return $this->resetTokenHash;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function clearResetToken(): static
    {
        $this->resetToken = null;
        $this->resetTokenHash = null;
        $this->resetTokenExpiresAt = null;
        return $this;
    }

    /**
     * Validate a provided token against the stored hash and expiry.
     */
    public function verifyResetToken(string $token): bool
    {
        if (!$this->resetTokenHash || !$this->resetTokenExpiresAt) {
            return false;
        }

        // check expiry first
        if ($this->resetTokenExpiresAt <= new DateTimeImmutable()) {
            return false;
        }

        $givenHash = hash('sha256', $token);
        return hash_equals($this->resetTokenHash, $givenHash);
    }

    

    

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    public function isResetTokenValid(): bool
    {
        if (!$this->resetToken || !$this->resetTokenExpiresAt) {
            return false;
        }
        return $this->resetTokenExpiresAt > new \DateTimeImmutable();
    }



    /**
     * @see UserInterface
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    /**
     * @return Collection<int, Post>
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    public function addPost(Post $post): static
    {
        if (!$this->posts->contains($post)) {
            $this->posts->add($post);
            $post->setAuthor($this);
        }

        return $this;
    }

    public function removePost(Post $post): static
    {
        if ($this->posts->removeElement($post)) {
            // set the owning side to null (unless already changed)
            if ($post->getAuthor() === $this) {
                $post->setAuthor(null);
            }
        }

        return $this;
    }

    
}

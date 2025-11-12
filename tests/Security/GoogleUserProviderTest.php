<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\GoogleUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\GoogleUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for the GoogleUserProvider.
 */
class GoogleUserProviderTest extends TestCase
{
    private EntityManagerInterface $entityManagerMock;
    private UserRepository $userRepositoryMock;
    private GoogleUserProvider $provider;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->userRepositoryMock = $this->createMock(UserRepository::class);

        $this->provider = new GoogleUserProvider($this->entityManagerMock, $this->userRepositoryMock);
    }

    /**
     * Tests loadUserByOAuthUser when a user with the Google ID already exists.
     */
    public function testLoadUserByOAuthUserExistingGoogleId(): void
    {
        $googleUserMock = $this->createMock(GoogleUser::class);
        $googleUserMock->method('getId')->willReturn('google_id_123');
        $googleUserMock->method('getEmail')->willReturn('test@example.com');
        $googleUserMock->method('getName')->willReturn('Test User');
        $googleUserMock->method('getAvatar')->willReturn('http://example.com/avatar.jpg');

        $existingUser = new User();
        $existingUser->setEmail('test@example.com');
        $existingUser->setGoogleId('google_id_123');
        $existingUser->setName('Old Name'); // Simulate a name change scenario
        $existingUser->setAvatarUrl('http://example.com/old_avatar.jpg');

        $this->userRepositoryMock->expects($this->once())
            ->method('findOneBy')
            ->with(['googleId' => 'google_id_123'])
            ->willReturn($existingUser);

        // Expect persist and flush if user data changes
        $this->entityManagerMock->expects($this->atLeastOnce())
            ->method('persist')
            ->with($existingUser);
        $this->entityManagerMock->expects($this->atLeastOnce())
            ->method('flush');

        $user = $this->provider->loadUserByOAuthUser($googleUserMock);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('test@example.com', $user->getEmail());
        $this->assertEquals('google_id_123', $user->getGoogleId());
        $this->assertEquals('Test User', $user->getName()); // Should be updated
        $this->assertEquals('http://example.com/avatar.jpg', $user->getAvatarUrl()); // Should be updated
    }

    /**
     * Tests loadUserByOAuthUser when no user with Google ID but with email exists.
     */
    public function testLoadUserByOAuthUserExistingEmailNoGoogleId(): void
    {
        $googleUserMock = $this->createMock(GoogleUser::class);
        $googleUserMock->method('getId')->willReturn('google_id_123');
        $googleUserMock->method('getEmail')->willReturn('test@example.com');
        $googleUserMock->method('getName')->willReturn('Test User');
        $googleUserMock->method('getAvatar')->willReturn('http://example.com/avatar.jpg');

        $existingUser = new User();
        $existingUser->setEmail('test@example.com');
        $existingUser->setRoles(['ROLE_USER']);

        $this->userRepositoryMock->expects($this->exactly(2)) // Called once for googleId, once for email
            ->method('findOneBy')
            ->willReturnMap([
                [['googleId' => 'google_id_123'], null, null],
                [['email' => 'test@example.com'], null, $existingUser]
            ]);

        $this->entityManagerMock->expects($this->once())
            ->method('persist')
            ->with($existingUser);
        $this->entityManagerMock->expects($this->once())
            ->method('flush');

        $user = $this->provider->loadUserByOAuthUser($googleUserMock);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('test@example.com', $user->getEmail());
        $this->assertEquals('google_id_123', $user->getGoogleId());
        $this->assertEquals('Test User', $user->getName());
        $this->assertEquals('http://example.com/avatar.jpg', $user->getAvatarUrl());
    }

    /**
     * Tests loadUserByOAuthUser when a new user needs to be created.
     */
    public function testLoadUserByOAuthUserNewUser(): void
    {
        $googleUserMock = $this->createMock(GoogleUser::class);
        $googleUserMock->method('getId')->willReturn('google_id_new');
        $googleUserMock->method('getEmail')->willReturn('new_user@example.com');
        $googleUserMock->method('getName')->willReturn('New User');
        $googleUserMock->method('getAvatar')->willReturn('http://example.com/new_avatar.jpg');

        $this->userRepositoryMock->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturn(null);

        $this->entityManagerMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (User $user) {
                return $user->getEmail() === 'new_user@example.com'
                       && $user->getGoogleId() === 'google_id_new'
                       && $user->getName() === 'New User'
                       && $user->getAvatarUrl() === 'http://example.com/new_avatar.jpg';
            }));
        $this->entityManagerMock->expects($this->once())
            ->method('flush');

        $user = $this->provider->loadUserByOAuthUser($googleUserMock);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('new_user@example.com', $user->getEmail());
        $this->assertEquals('google_id_new', $user->getGoogleId());
        $this->assertEquals('New User', $user->getName());
        $this->assertEquals('http://example.com/new_avatar.jpg', $user->getAvatarUrl());
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    /**
     * Tests loadUserByOAuthUser throws UserNotFoundException if email is missing.
     */
    public function testLoadUserByOAuthUserThrowsExceptionIfEmailMissing(): void
    {
        $googleUserMock = $this->createMock(GoogleUser::class);
        $googleUserMock->method('getId')->willReturn('google_id_no_email');
        $googleUserMock->method('getEmail')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('Google user email not available.');

        $this->provider->loadUserByOAuthUser($googleUserMock);
    }

    /**
     * Tests refreshUser when the user is of the correct type and found in the database.
     */
    public function testRefreshUserSuccess(): void
    {
        $user = new User();
        $user->setId(1);
        $user->setEmail('refresh@example.com');

        $this->userRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($user);

        $refreshedUser = $this->provider->refreshUser($user);

        $this->assertSame($user, $refreshedUser);
    }

    /**
     * Tests refreshUser throws UnsupportedUserException for unsupported user classes.
     */
    public function testRefreshUserUnsupportedClass(): void
    {
        $unsupportedUser = $this->createMock(UserInterface::class);

        $this->expectException(UnsupportedUserException::class);
        $this->expectExceptionMessageMatches('/Invalid user class ".*".');

        $this->provider->refreshUser($unsupportedUser);
    }

    /**
     * Tests refreshUser throws UserNotFoundException if user is not found during refresh.
     */
    public function testRefreshUserNotFound(): void
    {
        $user = new User();
        $user->setId(999);

        $this->userRepositoryMock->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User with ID "999" not found.');

        $this->provider->refreshUser($user);
    }

    /**
     * Tests supportsClass returns true for the User entity.
     */
    public function testSupportsClassReturnsTrueForUserEntity(): void
    {
        $this->assertTrue($this->provider->supportsClass(User::class));
    }

    /**
     * Tests supportsClass returns false for other classes.
     */
    public function testSupportsClassReturnsFalseForOtherClasses(): void
    {
        $this->assertFalse($this->provider->supportsClass(\stdClass::class));
    }
}

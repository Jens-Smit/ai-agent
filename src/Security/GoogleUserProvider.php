<?php
namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Security\User\OAuthUserProvider;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Custom User Provider to load or create users from Google OAuth2 data.
 */
class GoogleUserProvider extends OAuthUserProvider
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;

    /**
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param UserRepository $userRepository The custom user repository.
     */
    public function __construct(EntityManagerInterface $entityManager, UserRepository $userRepository)
    {
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
    }

    /**
     * Loads a user based on the OAuth2 resource owner's data (GoogleUser).
     *
     * @param ResourceOwnerInterface $resourceOwner The OAuth2 resource owner (GoogleUser).
     * @return UserInterface The user object.
     * @throws UserNotFoundException If the user cannot be found or created.
     */
    public function loadUserByOAuthUser(ResourceOwnerInterface $resourceOwner): UserInterface
    {
        /** @var GoogleUser $googleUser */
        $googleUser = $resourceOwner;

        if (null === $googleUser->getEmail()) {
            throw new UserNotFoundException('Google user email not available.');
        }

        // 1. Try to find the user by Google ID
        $user = $this->userRepository->findOneBy(['googleId' => $googleUser->getId()]);

        if (null === $user) {
            // 2. If no user with this Google ID, try to find by email
            $user = $this->userRepository->findOneBy(['email' => $googleUser->getEmail()]);

            if (null === $user) {
                // 3. If no user with this email, create a new one
                $user = new User();
                $user->setEmail($googleUser->getEmail());
                $user->setRoles(['ROLE_USER']);
                // Set additional fields from Google profile (setzen Sie nur Felder, die in Ihrer User-Entity existieren)
                $user->setName($googleUser->getName());
            }
            // Link the (new or existing) user with the Google ID
            $user->setGoogleId($googleUser->getId());
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } else {
            // Update existing user data if needed (e.g., name)
            if ($user->getName() !== $googleUser->getName()) {
                $user->setName($googleUser->getName());
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }
        }

        return $user;
    }

    /**
     * Refreshes the user after being re-loaded from the session.
     *
     * @param UserInterface $user The user to refresh.
     * @return UserInterface The refreshed user.
     * @throws UnsupportedUserException If the user class is not supported.
     * @throws UserNotFoundException If the user is not found in the database.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', get_class($user)));
        }

        // Always reload the user from the database to ensure data is current.
        $reloadedUser = $this->userRepository->find($user->getId());

        if (null === $reloadedUser) {
            throw new UserNotFoundException(sprintf('User with ID "%s" not found.', $user->getId()));
        }

        return $reloadedUser;
    }

    /**
     * Tells Symfony that this Provider supports the User class.
     *
     * @param string $class The class name to check.
     * @return bool True if the class is supported, false otherwise.
     */
    public function supportsClass($class): bool
    {
        return $class === User::class;
    }
}
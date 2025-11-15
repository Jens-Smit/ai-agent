<?php
// src/Security/GoogleAuthenticator.php
namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;


class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager
    ) {}

    public function supports(Request $request): ?bool
    {
        // Route der Callback-URL
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);
        /** @var GoogleUser $googleUser */
        $googleUser = $client->fetchUserFromToken($accessToken);

        $email = $googleUser->getEmail();

        return new SelfValidatingPassport(
            new UserBadge($email, function (string $userIdentifier) use ($googleUser, $accessToken) {
                $user = $this->userRepository->findOneBy(['email' => $userIdentifier]);
                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setRoles(['ROLE_USER']);
                }

                // Google-spezifische Felder speichern
                $user->setGoogleId($googleUser->getId());
                $user->setName($googleUser->getName());
                $user->setAvatarUrl($googleUser->getAvatar());

                // Token speichern
                $user->setGoogleAccessToken($accessToken->getToken());
                if ($accessToken->getRefreshToken()) {
                    $user->setGoogleRefreshToken($accessToken->getRefreshToken());
                }
                if ($accessToken->getExpires()) {
                    $user->setGoogleTokenExpiresAt(
                        (new \DateTimeImmutable())->setTimestamp($accessToken->getExpires())
                    );
                }

                $this->em->persist($user);
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        $jwt = $this->jwtManager->create($user);

        // Optional: setze das JWT als Cookie
        $response = new RedirectResponse('http://127.0.0.1:3000/dashboard#token=' . $jwt); // oder deine Erfolgs-URL
        $response->headers->setCookie(
            // Beispiel-Name, Domain etc. anpassen
            \Symfony\Component\HttpFoundation\Cookie::create('BEARER', $jwt, time() + 3600, '/', null, true, true)
        );

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse('/login?error=oauth_failed');
    }
}

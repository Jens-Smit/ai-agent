<?php
namespace App\Security;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
class GoogleAuthenticator extends AbstractAuthenticator
{
    private ClientRegistry $clientRegistry;
    private UserProviderInterface $userProvider;
    private UrlGeneratorInterface $router;
    private UserRepository $users;
    private EntityManagerInterface $em;
    private JWTTokenManagerInterface $jwtManager;

    public function __construct(
        ClientRegistry $clientRegistry, 
        UserRepository $users,
        UserProviderInterface $userProvider, 
        UrlGeneratorInterface $router, // Router für die Redirects injizieren
        EntityManagerInterface $em,
        JWTTokenManagerInterface $jwtManager,
    )
    {
        $this->clientRegistry = $clientRegistry;
        $this->userProvider = $userProvider;
        $this->router = $router;
        $this->em = $em;
        $this->users = $users; 
        $this->jwtManager = $jwtManager;
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $client->getAccessToken();
        $googleUser = $client->fetchUserFromToken($accessToken);

        return new SelfValidatingPassport(
            new UserBadge($googleUser->getEmail(), function (string $email) use ($googleUser) {
                // 1) User laden
                $user = $this->users->findOneBy(['email' => $email]);

                // 2) Falls nicht vorhanden: neu anlegen, Default-Rolle setzen
                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setGoogleId($googleUser->getId());
                    $user->setName($googleUser->getName());
                    $user->setAvatarUrl($googleUser->getAvatar());
                    $user->setRoles(['ROLE_USER']); // wichtig!
                    $this->em->persist($user);
                } else {
                    // 3) Bestehenden User mindestens mit Default-Rolle absichern
                    if (empty($user->getRoles())) {
                        $user->setRoles(['ROLE_USER']);
                    }
                    // Optional: Profil-Daten aktualisieren
                    $user->setName($googleUser->getName());
                    $user->setAvatarUrl($googleUser->getAvatar());
                    $user->setGoogleId($googleUser->getId());
                }

                // 4) Speichern (nur einmal hier — verhindert Lazy-Zugriff mit NULL-Rollen)
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        $jwt = $this->jwtManager->create($user);

        // Cookie-Dauer (z. B. 1 Stunde)
        $lifetime = 3600;
        $expiresAt = time() + $lifetime;

        $cookie = Cookie::create(
            'BEARER',           // Name
            $jwt,               // Value
            $expiresAt,         // Expires timestamp
            '/',                // Path
            null,               // Domain (null = current host). Set your frontend domain if needed.
            true,               // Secure (nur HTTPS)
            true,               // HttpOnly (true verhindert JS-Lesen; Setze false, wenn SPA es per JS lesen soll)
            false,              // Raw
            'Lax'               // SameSite: 'None' | 'Lax' | 'Strict'
        );

        $frontend = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000', '/');
        $response = new RedirectResponse($frontend . '/auth/success');

        // Cookie anhängen
        $response->headers->setCookie($cookie);

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Fehlerbehandlung: Redirect zur Login-Seite mit Flash-Meldung
        $request->getSession()->getFlashBag()->add('error', 'Google Login fehlgeschlagen: ' . $exception->getMessage());
        return new RedirectResponse($this->router->generate('app_login'));
    }
}
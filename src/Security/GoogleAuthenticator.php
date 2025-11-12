<?php
namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Response;

class GoogleAuthenticator extends AbstractAuthenticator
{
    private ClientRegistry $clientRegistry;
    private UserProviderInterface $userProvider;

    public function __construct(ClientRegistry $clientRegistry, UserProviderInterface $userProvider)
    {
        $this->clientRegistry = $clientRegistry;
        $this->userProvider = $userProvider;
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
            new UserBadge($googleUser->getEmail(), function ($email) {
                return $this->userProvider->loadUserByIdentifier($email);
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        // Weiterleitung nach erfolgreichem Login
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Fehlerbehandlung
        return null;
    }
}

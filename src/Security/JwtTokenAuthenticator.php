<?php

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
// Wir benötigen dieses Interface, um die Methode start() hinzuzufügen,
// obwohl wir von AbstractAuthenticator erben.
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;


// Die Klasse muss AuthenticationEntryPointInterface implementieren, um die start()-Methode bereitzustellen.
class JwtTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserProviderInterface $userProvider
    ) {}

    public function supports(Request $request): ?bool
    {
        $accessTokenCookieName = 'BEARER';
        // Wir prüfen, ob das Cookie existiert und nicht leer ist.
        return $request->cookies->has($accessTokenCookieName) 
           && !empty($request->cookies->get($accessTokenCookieName));
    }

    
    public function authenticate(Request $request): Passport
    {
        $accessTokenCookieName = 'BEARER'; 
        $token = $request->cookies->get($accessTokenCookieName);

        // Die Prüfung ist redundant wegen supports(), dient aber als Sicherheitsnetz,
        // falls der Token null oder leer ist. Wir verwenden CustomUserMessageAuthenticationException
        // anstelle der generischen AuthenticationException für bessere Fehlermeldungen.
        if (!$token) {
             throw new CustomUserMessageAuthenticationException('Access Token Cookie nicht gefunden. Authentifizierung fehlgeschlagen.');
        }

        try {
            $payload = $this->jwtManager->parse($token);
            
            if (!isset($payload['username'])) {
                throw new CustomUserMessageAuthenticationException('Ungültiger Token: Benutzername fehlt.');
            }

            return new SelfValidatingPassport(
                new UserBadge($payload['username'], function ($userIdentifier) {
                    return $this->userProvider->loadUserByIdentifier($userIdentifier);
                })
            );
        } catch (\Exception $e) {
            // Fängt alle JWT-bezogenen Fehler (Signatur, Ablauf) ab
            throw new CustomUserMessageAuthenticationException('Ungültiger oder abgelaufener Token.');
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Null = weiter mit dem Request
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Diese Methode wird aufgerufen, wenn die AUTHENTICATE-Methode fehlschlägt.
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessage()
        ], Response::HTTP_UNAUTHORIZED);
    }
    
    /**
     * DIES IST DIE FEHLENDE METHODE, DIE DEN 500-FEHLER BEHEBT!
     * Sie wird aufgerufen, wenn supports() false zurückgibt (also KEIN Token gesendet wurde) 
     * und ein geschützter Endpunkt aufgerufen wird. Sie erzeugt den erwarteten 401-Response.
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'error' => 'Authentifizierung erforderlich',
            'message' => 'Es wurde kein gültiges Autorisierungs-Cookie (BEARER) gefunden.'
        ], Response::HTTP_UNAUTHORIZED);
    }
}

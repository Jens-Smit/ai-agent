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
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

// Die Klasse liest den JWT aus dem Cookie und authentifiziert den Benutzer.
class JwtTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserProviderInterface $userProvider
    ) {}

    /**
     * Prüft, ob dieser Authenticator für die aktuelle Anfrage zuständig ist.
     * Er ist zuständig, wenn das 'BEARER' Cookie vorhanden ist.
     */
    public function supports(Request $request): ?bool
    {
        $accessTokenCookieName = 'BEARER';
        // Nur unterstützen, wenn das Cookie vorhanden ist und nicht leer.
        return $request->cookies->has($accessTokenCookieName) 
           && !empty($request->cookies->get($accessTokenCookieName));
    }

    /**
     * Liest den JWT aus dem Cookie und erstellt ein Passport-Objekt.
     */
    public function authenticate(Request $request): Passport
    {
        $accessTokenCookieName = 'BEARER'; 
        $token = $request->cookies->get($accessTokenCookieName);

        // Dies sollte nicht fehlschlagen, da supports() true war, aber dient als Sicherheitsnetz.
        if (!$token) {
              throw new CustomUserMessageAuthenticationException('Access Token Cookie nicht gefunden.');
        }

        try {
            // Token parsen und Payload extrahieren.
            $payload = $this->jwtManager->parse($token);
            
            if (!isset($payload['username'])) {
                throw new CustomUserMessageAuthenticationException('Ungültiger Token: Benutzername fehlt.');
            }

            // Erstellt den Passport mit dem UserBadge, um den Benutzer zu laden.
            return new SelfValidatingPassport(
                new UserBadge($payload['username'], function ($userIdentifier) {
                    return $this->userProvider->loadUserByIdentifier($userIdentifier);
                })
            );
        } catch (\Exception $e) {
            // Fängt alle JWT-Fehler ab (Signatur, Ablaufzeit, etc.).
            // Wenn der Fehler weiterhin besteht, können Sie hier temporär dd($e); einfügen.
            dd($e->getMessage());
            throw new CustomUserMessageAuthenticationException('Ungültiger oder abgelaufener Token.');
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Erfolg: Einfach fortfahren.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Fehler: 401 JSON Response zurückgeben.
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessage()
        ], Response::HTTP_UNAUTHORIZED);
    }
    
    /**
     * Wird aufgerufen, wenn supports() false zurückgibt und der Nutzer nicht authentifiziert ist.
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'error' => 'Authentifizierung erforderlich',
            'message' => 'Es wurde kein gültiges Autorisierungs-Cookie (BEARER) gefunden.'
        ], Response::HTTP_UNAUTHORIZED);
    }
}
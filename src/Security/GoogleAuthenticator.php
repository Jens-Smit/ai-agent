<?php

namespace App\Security;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\HttpFoundation\Request;
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
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie; // Not needed anymore, but keeping the use statement for completeness

class GoogleAuthenticator extends OAuth2Authenticator
{
    // Wichtig: Dies ist die URL, zu der Ihr Frontend nach dem OAuth-Callback weiterleiten soll.
    private const FRONTEND_REDIRECT_URL = 'https://127.0.0.1:3000/dashboard';

    public function __construct(
        private ClientRegistry $clientRegistry,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private LoggerInterface $logger
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');

        // Debug: log incoming OAuth callback parameters (code, state, prompt etc.)
        $this->logger->debug('Google OAuth callback params', [
            'query' => $request->query->all(),
            'route' => $request->attributes->get('_route')
        ]);

        try {
            /** @var AccessTokenInterface $accessToken */
            $accessToken = $this->fetchAccessToken($client);
        } catch (\Throwable $e) {
            // Fetching access token failed -> create AuthenticationException to trigger failure flow
            $this->logger->error('Failed to fetch access token from Google', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new AuthenticationException('Google OAuth: could not fetch access token.');
        }

        // Optional: if the library returns an array-like token, log its keys
        try {
            $tokenArray = method_exists($accessToken, 'jsonSerialize')
                ? $accessToken->jsonSerialize()
                : (is_array($accessToken) ? $accessToken : null);

            if (is_array($tokenArray)) {
                $this->logger->info('Google OAuth token received', [
                    'keys' => array_keys($tokenArray),
                    'has_access_token' => isset($tokenArray['access_token']),
                    'has_refresh_token' => isset($tokenArray['refresh_token']),
                    'expires' => $tokenArray['expires'] ?? null,
                ]);
            } else {
                $this->logger->info('Google OAuth token received (non-array)', [
                    'accessTokenClass' => \get_class($accessToken)
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not inspect access token structure', [
                'exception' => $e->getMessage()
            ]);
        }

        try {
            /** @var GoogleUser $googleUser */
            $googleUser = $client->fetchUserFromToken($accessToken);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch Google user from token', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new AuthenticationException('Google OAuth: could not fetch user info.');
        }

        $email = $googleUser->getEmail();
        if (empty($email)) {
            $this->logger->error('Google user has no email', [
                'user' => method_exists($googleUser, 'toArray') ? $googleUser->toArray() : null
            ]);
            throw new AuthenticationException('Google OAuth: no email returned.');
        }

        return new SelfValidatingPassport(
            new UserBadge($email, function (string $userIdentifier) use ($googleUser, $accessToken) {
                // get full token array safely
                $tokenArray = method_exists($accessToken, 'jsonSerialize')
                    ? $accessToken->jsonSerialize()
                    : (is_array($accessToken) ? $accessToken : []);

                // Ensure we have an array
                if (!is_array($tokenArray)) {
                    $this->logger->warning('Access token could not be converted to array, normalizing', [
                        'accessTokenClass' => \get_class($accessToken)
                    ]);
                    $tokenArray = [];
                }

                // Very explicit logging for debugging later
                $this->logger->debug('Processing token for user', [
                    'email' => $userIdentifier,
                    'token_keys' => array_keys($tokenArray)
                ]);

                // If refresh token is missing, bail out with an AuthenticationException
                if (empty($tokenArray['refresh_token'])) {
                    $this->logger->error('Missing refresh_token from Google OAuth', [
                        'email' => $userIdentifier,
                        'token_snapshot' => array_intersect_key($tokenArray, array_flip(['access_token','expires','scope','token_type','id_token']))
                    ]);
                    // Informative message so client can show a re-auth hint
                    throw new AuthenticationException('Google OAuth did not provide a refresh token. Please re-authenticate with offline access and consent.');
                }

                $user = $this->userRepository->findOneBy(['email' => $userIdentifier]);

                if (!$user) {
                    $user = new User();
                    $user->setEmail($googleUser->getEmail());
                    $user->setRoles(['ROLE_USER']);
                }

                $user->setGoogleId($googleUser->getId());
                $user->setName($googleUser->getName());
                $user->setAvatarUrl($googleUser->getAvatar());

                // Store full token JSON and explicit refresh token + expires timestamp
                $user->setGoogleAccessToken(json_encode($tokenArray));
                $user->setGoogleRefreshToken($tokenArray['refresh_token']);

                if (isset($tokenArray['expires']) && is_numeric($tokenArray['expires'])) {
                    $user->setGoogleTokenExpiresAt(
                        (new \DateTimeImmutable())->setTimestamp((int) $tokenArray['expires'])
                    );
                }

                try {
                    $this->em->persist($user);
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('Could not persist user after Google OAuth', [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new AuthenticationException('Could not store Google OAuth token.');
                }

                $this->logger->info('User OAuth token stored', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                    'has_refresh_token' => isset($tokenArray['refresh_token'])
                ]);

                return $user;
            })
        );
    }

    // Symfony onAuthenticationSuccess (Final Cookie-Only Version)

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {

        $user = $token->getUser();



        // 1. Tokens generieren

        $jwt = $this->jwtManager->create($user);

        $refreshTokenObject = $this->refreshTokenGenerator->createForUserWithTtl($user, 604800);

        $this->refreshTokenManager->save($refreshTokenObject);

        $refreshTokenString = $refreshTokenObject->getRefreshToken();



        // 2. Redirect-URL nur zur Callback-Seite (KEINE TOKENS IN DER URL!)

        $redirectUri = self::FRONTEND_REDIRECT_URL; // Sollte z.B. http://127.0.0.1:3000/dashboard sein



        // 3. Response erstellen

        $response = new RedirectResponse($redirectUri);



        // 4. Cookies setzen (HttpOnly = TRUE für Sicherheit)

        $accessTokenCookie = Cookie::create('BEARER')

            ->withValue($jwt)

            ->withExpires(time() + 3600)

            ->withPath('/')

            ->withSecure(false) // MUSS auf true, wenn SameSite=None

            ->withHttpOnly(true) // WICHTIG: Nicht lesbar für JS

            ->withSameSite(Cookie::SAMESITE_LAX); // Gut für den Redirect



        $refreshTokenCookie = Cookie::create('refresh_token')

            ->withValue($refreshTokenString)

            ->withExpires(time() + 604800)

            ->withPath('/')

            ->withSecure(false)

            ->withHttpOnly(true)

            ->withSameSite(Cookie::SAMESITE_LAX);



        $response->headers->setCookie($accessTokenCookie);

        $response->headers->setCookie($refreshTokenCookie);



        $this->logger->info('OAuth Success: Redirecting with HttpOnly Cookies', [

            'userId' => $user->getId(),

            'target' => $redirectUri

        ]);



        return $response;

    }


    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->error('Google OAuth failed', [
            'error' => $exception->getMessage(),
            'query' => $request->query->all()
        ]);

        // Redirect mit Fehlermeldung als Query-Parameter
        return new RedirectResponse(
            self::FRONTEND_REDIRECT_URL . 
            '?error=oauth_failed&hint=reconsent&message=' . 
            urlencode($exception->getMessage())
        );
    }
}
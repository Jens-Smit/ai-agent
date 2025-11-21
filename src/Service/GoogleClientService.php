<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Calendar;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleClientService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $router,
        private readonly HttpClientInterface $httpClient,   // <-- injizierter HttpClient
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $frontendUrl
    ) {
        $this->logger->info('GoogleClientService initialized', [
            'client_id_set' => !empty($this->googleClientId),
            'client_secret_set' => !empty($this->googleClientSecret),
            'client_id_length' => strlen($this->googleClientId),
            'frontend_url' => $this->frontendUrl
        ]);
    }

    private function buildBaseClient(): Client
    {
        $client = new Client();
        $client->setClientId($this->googleClientId);
        $client->setClientSecret($this->googleClientSecret);
        $client->setRedirectUri($this->frontendUrl . $this->router->generate('connect_google_check'));

        $client->addScope([
            Gmail::GMAIL_SEND,
            Gmail::GMAIL_READONLY,
            Gmail::GMAIL_MODIFY,
            Calendar::CALENDAR,
            'profile',
            'email'
        ]);

        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    public function getClientForUser(User $user): Client
    {
        $this->logger->info('Init Google client', [
            'userId' => $user->getId(),
            'email' => $user->getEmail()
        ]);

        $rawToken = $user->getGoogleAccessToken();
        if (!$rawToken) {
            $this->logger->error('No Google OAuth token stored');
            throw new \RuntimeException('Google OAuth token missing. Please log in again.');
        }

        $token = json_decode($rawToken, true);
        if (!is_array($token)) {
            $this->logger->error('Invalid token JSON');
            throw new \RuntimeException('Invalid Google token format.');
        }

        $this->logger->info('Loaded token from DB', [
            'keys' => array_keys($token),
            'has_access_token' => isset($token['access_token']),
            'has_refresh_token' => isset($token['refresh_token']),
        ]);

        if (empty($token['refresh_token'])) {
            $this->logger->error('Missing refresh token');
            throw new \RuntimeException('Refresh token missing. Please log in again to reconnect Google.');
        }

        $client = $this->buildBaseClient();
        $client->setAccessToken($token);

        if (!$client->isAccessTokenExpired()) {
            $this->logger->info('Access token still valid');
            return $client;
        }

        $this->logger->info('Access token expired, refreshing...', [
            'refresh_token_present' => !empty($token['refresh_token']),
            'client_id_set' => !empty($this->googleClientId)
        ]);

        try {
            $newToken = $client->fetchAccessTokenWithRefreshToken($token['refresh_token']);

            $this->logger->info('Refresh response', [
                'new_keys' => array_keys($newToken),
                'has_error' => isset($newToken['error'])
            ]);

            if (!empty($newToken['error'])) {
                $msg = $newToken['error'] . ' - ' . ($newToken['error_description'] ?? '');
                $this->logger->error('Token refresh API error', [
                    'error' => $newToken['error'],
                    'description' => $newToken['error_description'] ?? 'no description'
                ]);
                throw new \RuntimeException($msg);
            }

            $mergedToken = array_merge($token, $newToken);
            if (!isset($mergedToken['refresh_token'])) {
                $mergedToken['refresh_token'] = $token['refresh_token'];
            }

            if (isset($mergedToken['expires_in'])) {
                $mergedToken['expires'] = time() + (int)$mergedToken['expires_in'];
            }

            $user->setGoogleAccessToken(json_encode($mergedToken));
            if (isset($mergedToken['expires'])) {
                $user->setGoogleTokenExpiresAt(
                    (new \DateTimeImmutable())->setTimestamp((int)$mergedToken['expires'])
                );
            }
            $this->entityManager->flush();

            $this->logger->info('Token updated in DB', [
                'new_expires' => $mergedToken['expires'] ?? 'unknown'
            ]);

            $client->setAccessToken($mergedToken);
            return $client;

        } catch (\Throwable $e) {
            $this->logger->error('Token refresh failed', [
                'message' => $e->getMessage(),
                'has_refresh_token' => !empty($token['refresh_token']),
                'client_id_configured' => !empty($this->googleClientId)
            ]);

            throw new \RuntimeException('Token refresh failed: ' . $e->getMessage());
        }
    }

    public function getAuthUrl(): string
    {
        return $this->buildBaseClient()->createAuthUrl();
    }

    /**
     * Beispiel-Methode: Gemini API Call über den dekorierten HttpClient
     */
    public function callGeminiApi(User $user, array $payload): array
    {
        $client = $this->getClientForUser($user);
        $accessToken = $client->getAccessToken()['access_token'] ?? null;

        if (!$accessToken) {
            throw new \RuntimeException('No valid access token for Gemini API');
        }

        $response = $this->httpClient->request(
            'POST',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]
        );

        return $response->toArray(false);
    }
}

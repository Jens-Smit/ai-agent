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

class GoogleClientService
{
    private Client $googleClient;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $router,
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $frontendUrl
    ) {
        $this->googleClient = new Client();
        $this->googleClient->setClientId($this->googleClientId);
        $this->googleClient->setClientSecret($this->googleClientSecret);

        $this->googleClient->setRedirectUri(
            $this->frontendUrl . $this->router->generate('connect_google_check')
        );

        $this->googleClient->addScope([
            Gmail::GMAIL_SEND,
            Calendar::CALENDAR,
            'profile',
            'email'
        ]);

        $this->googleClient->setAccessType('offline');
        $this->googleClient->setPrompt('consent');
    }

    public function getClientForUser(User $user): Client
    {
        $rawToken = $user->getGoogleAccessToken();

        if (!$rawToken) {
            throw new \RuntimeException('Google OAuth token missing. Please re-authenticate.');
        }

        // komplettes Token aus DB
        $token = json_decode($rawToken, true);

        if (!is_array($token)) {
            throw new \RuntimeException('Invalid Google token format.');
        }

        $this->googleClient->setAccessToken($token);

        if ($this->googleClient->isAccessTokenExpired()) {
            $this->logger->info('Refreshing expired Google token.');

            $refreshed = $this->googleClient->fetchAccessTokenWithRefreshToken();

            if (!empty($refreshed['error'])) {
                throw new \RuntimeException('Token refresh failed: ' . $refreshed['error']);
            }

            // Token zusammenführen
            $updatedToken = array_merge($token, $refreshed);

            // speichern
            $user->setGoogleAccessToken(json_encode($updatedToken));
            $this->entityManager->flush();

            $this->googleClient->setAccessToken($updatedToken);
        }

        return $this->googleClient;
    }

    public function getAuthUrl(): string
    {
        return $this->googleClient->createAuthUrl();
    }
}

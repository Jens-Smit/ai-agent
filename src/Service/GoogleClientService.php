<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Calendar; // Hinzugefügt, da es im Controller verwendet wird, falls nötig.
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
        private readonly string $frontendUrl // Wird für die Umleitungs-URI verwendet
    ) {
        $this->googleClient = new Client();
        $this->googleClient->setClientId($this->googleClientId);
        $this->googleClient->setClientSecret($this->googleClientSecret);
       

        // Die Redirect URI muss genau mit der in der Google Console übereinstimmen.
        // Hier wird sie dynamisch aus der frontendUrl und der Route generiert.
        $this->googleClient->setRedirectUri($this->frontendUrl . $this->router->generate('connect_google_check'));
        
        // Hinzufügen der erforderlichen Scopes
        $this->googleClient->addScope([
            Gmail::GMAIL_SEND,
            Calendar::CALENDAR, // Beispiel: falls der Kalender auch benötigt wird
            'profile',
            'email'
        ]);
        $this->googleClient->setAccessType('offline');
        $this->googleClient->setPrompt('consent'); // Erfordert Zustimmung, um Refresh-Token zu erhalten
    }

    public function getClientForUser(User $user): Client
    {
        $accessToken = $user->getGoogleAccessToken();
        $refreshToken = $user->getGoogleRefreshToken();

        if (!$accessToken || !$refreshToken) {
            $this->logger->error('Google OAuth tokens not found for user.', ['user_id' => $user->getId()]);
            throw new \RuntimeException('Google OAuth tokens not found for user. Please re-authenticate.');
        }

        $this->googleClient->setAccessToken(json_decode($accessToken, true)); // Access Token als Array setzen

        // Prüfen, ob der Access Token abgelaufen ist
        if ($this->googleClient->isAccessTokenExpired()) {
            $this->logger->info('Google access token expired, attempting to refresh.', ['user_id' => $user->getId()]);
            try {
                $this->googleClient->fetchAccessTokenWithRefreshToken($refreshToken);
                $newAccessToken = $this->googleClient->getAccessToken();
                
                // Speichere den neuen Access Token (und ggf. einen neuen Refresh Token)
                $user->setGoogleAccessToken(json_encode($newAccessToken));
                if (isset($newAccessToken['refresh_token'])) {
                    $user->setGoogleRefreshToken($newAccessToken['refresh_token']);
                }
                $this->entityManager->flush();
                $this->logger->info('Google access token refreshed and saved for user.', ['user_id' => $user->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to refresh Google access token: ' . $e->getMessage(), ['user_id' => $user->getId(), 'exception' => $e]);
                throw new \RuntimeException('Failed to refresh Google access token. Please re-authenticate.', 0, $e);
            }
        }

        return $this->googleClient;
    }

    /**
     * Returns the URL to initiate the Google OAuth consent screen.
     */
    public function getAuthUrl(): string
    {
        return $this->googleClient->createAuthUrl();
    }
}

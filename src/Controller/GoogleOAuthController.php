<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use App\Security\GoogleUserProvider;

/**
 * Handles Google OAuth2 authentication flow for React Frontend.
 */
class GoogleOAuthController extends AbstractController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly GoogleUserProvider $userProvider, 
        private readonly UrlGeneratorInterface $router,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    /**
     * Initiates Google OAuth flow
     */
    #[Route('/connect/google', name: 'google_oauth_start', methods: ['GET'])]
    public function connect(): RedirectResponse
    {
        $client = $this->clientRegistry->getClient('google');
        return $client->redirect(['profile', 'email']);
    }

    /**
     * Google OAuth Callback - Wird vom GoogleAuthenticator abgefangen
     * Wenn dieser Code erreicht wird, ist die Auth fehlgeschlagen
     */
    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function connectCheck(): Response
    {
        // Dieser Code sollte NIE erreicht werden, da der Authenticator den Request abfängt
        // Falls doch: Redirect zum Frontend mit Error
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
        return new RedirectResponse($frontendUrl . '/login?error=oauth_failed');
    }

    /**
     * Optional: API Endpoint um User-Daten nach OAuth zu holen
     * Wird vom React Frontend nach dem Callback verwendet
     */
    #[Route('/api/user', name: 'api_get_user', methods: ['GET'])]
    public function getUserData(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        return new JsonResponse([
            'status' => 'success',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'googleId' => $user->getGoogleId(),
                'avatarUrl' => $user->getAvatarUrl(),
                'roles' => $user->getRoles(),
            ]
        ]);
    }
}
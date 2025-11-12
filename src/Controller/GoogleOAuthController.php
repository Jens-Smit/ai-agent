<?php

declare(strict_types=1);

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles Google OAuth2 authentication flow.
 */
class GoogleOAuthController extends AbstractController
{
    // Die Router-Instanz wird hier zwar injiziert, aber im Controller nur für 
    // manuelle Redirects benötigt.
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UrlGeneratorInterface $router
    ) {
    }

    #[Route('/connect/google', name: 'google_oauth_start', methods: ['GET'])]
    public function connect(): RedirectResponse
    {
        /** @var GoogleClient $client */
        $client = $this->clientRegistry->getClient('google');

        // Fordert 'profile' und 'email' Scopes an
        return $client->redirect(['profile', 'email']); 
    }

    /**
     * DIESE METHODE MUSS LEER BLEIBEN.
     * Der Request wird vollständig vom GoogleAuthenticator abgefangen und verarbeitet.
     * Wenn der Request diesen Controller-Code erreicht, ist die Authentifizierung
     * fehlerhaft oder es wurde kein Benutzer gefunden.
     */
    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function connectCheck(): Response
    {
        // Fallback: Wenn der Authenticator fehlschlägt, leiten wir zum Login um.
        $this->addFlash('error', 'Google login failed: Please check your configuration.');
        return $this->redirectToRoute('app_login'); 
    }

    /**
     * Beispiel für eine geschützte Seite.
     */
    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    #[IsGranted("IS_AUTHENTICATED_FULLY")]
    public function profile(): Response
    {
        return $this->render('google_oauth/profile.html.twig', [
            'user' => $this->getUser(),
        ]);
    }
}
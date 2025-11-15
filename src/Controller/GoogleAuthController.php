<?php
//src/Controller/GoogleAuthController.php
namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

class GoogleAuthController extends AbstractController
{
    #[Route('/connect/google', name: 'connect_google_auth_start')]
    public function connect(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry
            ->getClient('google')
            ->redirect(
                ['email', 'profile'],
                ['prompt' => 'consent', 'access_type' => 'offline']
            );
    }

    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function check(): void
    {
        // Wird vom Authenticator verarbeitet. Normalerweise kommt man hier nicht an.
       
    }    

}

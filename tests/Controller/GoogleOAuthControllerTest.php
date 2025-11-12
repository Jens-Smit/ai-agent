<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GoogleOAuthController;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Security; // For Symfony 5.4 and earlier
// use Symfony\Bundle\SecurityBundle\Security as NewSecurity; // For Symfony 6.0 and later
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for the GoogleOAuthController.
 */
class GoogleOAuthControllerTest extends TestCase
{
    private GoogleOAuthController $controller;
    private ClientRegistry $clientRegistryMock;
    private OAuth2Client $oauth2ClientMock;
    private RouterInterface $routerMock;
    private TokenStorageInterface $tokenStorageMock;
    private Security $securityMock; // For Symfony 5.4 and earlier
    // private NewSecurity $securityMock; // For Symfony 6.0 and later

    protected function setUp(): void
    {
        $this->clientRegistryMock = $this->createMock(ClientRegistry::class);
        $this->oauth2ClientMock = $this->createMock(OAuth2Client::class);
        $this->routerMock = $this->createMock(RouterInterface::class);
        $this->tokenStorageMock = $this->createMock(TokenStorageInterface::class);

        // Mock for Symfony\Component\Security\Core\Security
        $this->securityMock = $this->createMock(Security::class);

        $this->controller = new GoogleOAuthController($this->clientRegistryMock/*, $this->securityMock*/);

        // Set the container for the controller to resolve some dependencies like Router and TokenStorage
        $containerMock = $this->createMock(ContainerInterface::class);
        $containerMock->method('has')->willReturn(true);
        $containerMock->method('get')->willReturnMap([
            ['router', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->routerMock],
            ['security.token_storage', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->tokenStorageMock],
            ['security.helper', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->securityMock], // Used by $this->getUser()
        ]);
        $this->controller->setContainer($containerMock);
    }

    /**
     * Tests that the connect method redirects to Google's authentication URL.
     */
    public function testConnectRedirectsToGoogle(): void
    {
        $this->clientRegistryMock->expects($this->once())
            ->method('getClient')
            ->with('google')
            ->willReturn($this->oauth2ClientMock);

        $this->oauth2ClientMock->expects($this->once())
            ->method('redirect')
            ->with(['profile', 'email'])
            ->willReturn(new RedirectResponse('https://accounts.google.com/oauth2/v2/auth?test=1'));

        $response = $this->controller->connect();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('accounts.google.com', $response->getTargetUrl());
    }

    /**
     * Tests that connectCheck handles successful authentication and redirects to the homepage.
     */
    public function testConnectCheckHandlesSuccessfulAuthentication(): void
    {
        $this->clientRegistryMock->expects($this->once())
            ->method('getClient')
            ->with('google')
            ->willReturn($this->oauth2ClientMock);

        // Mock a user being returned from the security system
        $mockUser = $this->createMock(UserInterface::class);
        $mockUser->method('getUserIdentifier')->willReturn('test@example.com');

        $tokenMock = $this->createMock(TokenInterface::class);
        $tokenMock->method('getUser')->willReturn($mockUser);

        $this->tokenStorageMock->expects($this->once())
            ->method('getToken')
            ->willReturn($tokenMock);

        // Mock the getUser() method of the controller to return our mock user
        $this->securityMock->expects($this->once())
            ->method('getUser')
            ->willReturn($mockUser);

        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        // Mock redirectToRoute
        $this->routerMock->method('generate')->with('app_homepage')->willReturn('/homepage');

        $response = $this->controller->connectCheck($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/homepage', $response->getTargetUrl());
        $this->assertContains(['success' => 'Successfully logged in with Google!'], $session->getFlashBag()->all());
    }

    /**
     * Tests that connectCheck handles authentication failure and redirects to the login page.
     */
    public function testConnectCheckHandlesAuthenticationFailure(): void
    {
        $this->clientRegistryMock->expects($this->once())
            ->method('getClient')
            ->with('google')
            ->willReturn($this->oauth2ClientMock);

        // Simulate an IdentityProviderException (e.g., user denied permissions)
        $this->oauth2ClientMock->expects($this->once())
            ->method('fetchUser') // This method is called internally by the bundle when authentication fails or succeeds
            ->willThrowException(new IdentityProviderException('Access denied', 0, []));

        // Mock the getUser() method to return null in case of failure
        $this->securityMock->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        // Mock redirectToRoute
        $this->routerMock->method('generate')->with('app_login')->willReturn('/login');

        $response = $this->controller->connectCheck($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/login', $response->getTargetUrl());
        $this->assertContains(['error' => 'Google login failed: Access denied'], $session->getFlashBag()->all());
    }

    /**
     * Tests the profile page is rendered for authenticated users.
     */
    public function testProfilePage(): void
    {
        $mockUser = $this->createMock(UserInterface::class);
        $mockUser->method('getUserIdentifier')->willReturn('test@example.com');

        $tokenMock = $this->createMock(TokenInterface::class);
        $tokenMock->method('getUser')->willReturn($mockUser);

        $this->tokenStorageMock->expects($this->once())
            ->method('getToken')
            ->willReturn($tokenMock);

        // Mock the getUser() method of the controller for the profile action
        $this->securityMock->expects($this->once())
            ->method('getUser')
            ->willReturn($mockUser);

        // Mock the render method (assuming twig is configured in a functional test, for unit it's sufficient to mock)
        $this->controller->expects($this->once())
            ->method('render')
            ->with('google_oauth/profile.html.twig', $this->callback(function ($params) use ($mockUser) {
                return $params['user'] === $mockUser;
            }))
            ->willReturn(new Response('<html>Profile</html>'));

        $response = $this->controller->profile();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('Profile', $response->getContent());
    }
}

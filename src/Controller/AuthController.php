<?php

// src/Controller/AuthController.php

namespace App\Controller;

use OpenApi\Attributes as OA;
use App\DTO\RegisterRequestDTO;
use App\Service\AuthService;
use App\Service\PasswordService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly PasswordService $passwordService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly EntityManagerInterface $em,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'limiter.login_limiter')]
        private readonly RateLimiterFactory $loginLimiter,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'limiter.password_reset_limiter')]
        private readonly RateLimiterFactory $passwordResetLimiter,
    ) {}

    #[Route("/api/login", name: "api_login", methods: ["POST"])]
    #[OA\Post(
        path: "/api/login",
        summary: "Benutzer-Login",
        description: "Authentifiziert einen Benutzer und gibt Access- und Refresh-Token zurück.",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email","password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string", format: "password")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Login erfolgreich",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string"),
                        new OA\Property(property: "user", type: "object",
                            properties: [
                                new OA\Property(property: "email", type: "string")
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Ungültige Anmeldedaten"),
            new OA\Response(response: 429, description: "Zu viele Login-Versuche")
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $limiter = $this->loginLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            // ✅ Log suspicious activity
            $this->logger->warning('Rate limit exceeded for IP', [
                'ip' => $request->getClientIp(),
                'email' => $data['email'] ?? 'unknown'
            ]);
            
            return new JsonResponse([
                'error' => 'Zu viele Versuche',
                'retry_after' => $limiter->consume(0)->getRetryAfter()->getTimestamp()
            ], 429);
        }

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if (!$email || !$password) {
            return new JsonResponse(['error' => 'E-Mail und Passwort sind erforderlich.'], 400);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['error' => 'Ungültige Anmeldedaten.'], 401);
        }

        $accessToken = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, 604800);
        $this->refreshTokenManager->save($refreshToken);

        $accessTokenCookie = new Cookie('BEARER', $accessToken, time() + 3600, '/', null, false, true, false, 'lax');
        $refreshTokenCookie = new Cookie('refresh_token', $refreshToken->getRefreshToken(), time() + 604800, '/', null, false, true, false, 'lax');

        $response = new JsonResponse([
            'message' => 'Login erfolgreich.',
            'user' => ['email' => $user->getUserIdentifier()]
        ], 200);
        $response->headers->setCookie($accessTokenCookie);
        $response->headers->setCookie($refreshTokenCookie);

        return $response;
    }

    #[Route("/api/logout", name: "api_logout", methods: ["POST"])]
    #[OA\Post(
        path: "/api/logout",
        summary: "Benutzer-Logout",
        description: "Löscht Access- und Refresh-Token-Cookies.",
        tags: ["Authentication"],
        responses: [
            new OA\Response(
                response: 200,
                description: "Logout erfolgreich",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string")
                    ]
                )
            )
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $refreshTokenString = $request->cookies->get('refresh_token');
        if ($refreshTokenString) {
            $refreshToken = $this->refreshTokenManager->get($refreshTokenString);
            if ($refreshToken) {
                $this->refreshTokenManager->delete($refreshToken);
            }
        }

        $response = new JsonResponse(['message' => 'Logout erfolgreich.']);
        $response->headers->setCookie(new Cookie('BEARER', '', time() - 3600, '/', null, false, true, false, 'lax'));
        $response->headers->setCookie(new Cookie('refresh_token', '', time() - 3600, '/', null, false, true, false, 'lax'));

        return $response;
    }

    #[Route('/api/register', name: 'user_register', methods: ['POST'])]
    #[OA\Post(
        path: "/api/register",
        summary: "Benutzer registrieren",
        description: "Registriert einen neuen Benutzer mit E-Mail und Passwort.",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email","password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string", format: "password")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Benutzer erfolgreich registriert"),
            new OA\Response(response: 400, description: "Fehlerhafte Eingabe"),
            new OA\Response(response: 500, description: "Serverfehler")
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['email'], $data['password'])) {
            return new JsonResponse(['error' => 'E-Mail und Passwort sind erforderlich.'], 400);
        }
        if (strlen($data['password']) < 8) {
            return new JsonResponse(['error' => 'Passwort muss mindestens 8 Zeichen lang sein.'], 400);
        }

        $dto = new RegisterRequestDTO($data['email'], $data['password']);

        try {
            $this->authService->register($dto);
            return new JsonResponse(['message' => 'Benutzer erfolgreich registriert.'], 201);
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Registrierung fehlgeschlagen'], 500);
        }
    }

    #[Route('/api/password/request-reset', name: 'password_request_reset', methods: ['POST'])]
    #[OA\Post(
        path: "/api/password/request-reset",
        summary: "Passwort-Reset anfordern",
        description: "Sendet eine E-Mail für das Zurücksetzen des Passworts.",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Reset-E-Mail versendet"),
            new OA\Response(response: 429, description: "Zu viele Anfragen")
        ]
    )]
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $limiter = $this->passwordResetLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Zu viele Passwort-Reset-Anfragen. Bitte später erneut.'], 429);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['email'])) {
            return new JsonResponse(['error' => 'E-Mail ist erforderlich.'], 400);
        }

        try {
            $this->passwordService->requestPasswordReset($data['email']);
        } catch (\Throwable) {
        }

        return new JsonResponse(['message' => 'Falls die E-Mail existiert, wurde eine Reset-E-Mail versendet.'], 200);
    }

    #[Route('/api/password/reset', name: 'password_reset', methods: ['POST'])]
    #[OA\Post(
        path: "/api/password/reset",
        summary: "Passwort zurücksetzen",
        description: "Setzt das Passwort mithilfe des Tokens zurück.",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["token","newPassword"],
                properties: [
                    new OA\Property(property: "token", type: "string"),
                    new OA\Property(property: "newPassword", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Passwort erfolgreich zurückgesetzt"),
            new OA\Response(response: 400, description: "Ungültiger Token oder Eingabe"),
            new OA\Response(response: 500, description: "Serverfehler")
        ]
    )]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['token'], $data['newPassword'])) {
            return new JsonResponse(['error' => 'Token und neues Passwort sind erforderlich.'], 400);
        }

        try {
            $this->passwordService->resetPassword($data['token'], $data['newPassword']);
            return new JsonResponse(['message' => 'Passwort erfolgreich zurückgesetzt.'], 200);
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Fehler beim Zurücksetzen des Passworts'], 500);
        }
    }

    #[Route('/api/password/change', name: 'password_change', methods: ['POST'])]
    #[OA\Post(
        path: "/api/password/change",
        summary: "Passwort ändern",
        description: "Ändert das Passwort des aktuell angemeldeten Benutzers.",
        tags: ["Authentication"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["currentPassword","newPassword"],
                properties: [
                    new OA\Property(property: "currentPassword", type: "string"),
                    new OA\Property(property: "newPassword", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Passwort erfolgreich geändert"),
            new OA\Response(response: 400, description: "Fehlerhafte Eingabe"),
            new OA\Response(response: 401, description: "Nicht authentifiziert"),
            new OA\Response(response: 500, description: "Serverfehler")
        ]
    )]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Nicht authentifiziert.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['currentPassword'], $data['newPassword'])) {
            return new JsonResponse(['error' => 'Aktuelles und neues Passwort sind erforderlich.'], 400);
        }

        try {
            $this->passwordService->changePassword($user, $data['currentPassword'], $data['newPassword']);
            return new JsonResponse(['message' => 'Passwort erfolgreich geändert.'], 200);
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Fehler beim Ändern des Passworts'], 500);
        }
    }
}
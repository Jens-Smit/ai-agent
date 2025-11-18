<?php

namespace App\Controller;

use App\Entity\UserSettings;
use App\Repository\UserSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/user/settings')]
class UserSettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserSettingsRepository $repo
    ) {}

    #[Route('', name: 'api_get_user_settings', methods: ['GET'])]
    #[OA\Get(
        path: "/api/user/settings",
        summary: "Get current user's mail settings",
        tags: ["User Settings"],
        responses: [
            new OA\Response(response: 200, description: "Settings returned"),
            new OA\Response(response: 401, description: "Not authenticated")
        ]
    )]
    public function getSettings(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $settings = $this->repo->findOneBy(['user' => $user]);

        if (!$settings) {
            $settings = new UserSettings();
            $settings->setUser($user);
            $this->em->persist($settings);
            $this->em->flush();
        }

        return $this->json($this->serializeSettings($settings));
    }


    #[Route('', name: 'api_update_user_settings', methods: ['PUT'])]
    #[OA\Put(
        path: "/api/user/settings",
        summary: "Update user mail settings",
        tags: ["User Settings"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    // POP3
                    new OA\Property(property: "pop3Host", type: "string", nullable: true),
                    new OA\Property(property: "pop3Port", type: "integer", nullable: true),
                    new OA\Property(property: "pop3Encryption", type: "string", nullable: true),

                    // IMAP
                    new OA\Property(property: "imapHost", type: "string", nullable: true),
                    new OA\Property(property: "imapPort", type: "integer", nullable: true),
                    new OA\Property(property: "imapEncryption", type: "string", nullable: true),

                    // SMTP
                    new OA\Property(property: "smtpHost", type: "string", nullable: true),
                    new OA\Property(property: "smtpPort", type: "integer", nullable: true),
                    new OA\Property(property: "smtpEncryption", type: "string", nullable: true),
                    new OA\Property(property: "smtpUsername", type: "string", nullable: true),
                    new OA\Property(property: "smtpPassword", type: "string", nullable: true),

                    // Common
                    new OA\Property(property: "emailAddress", type: "string", nullable: true)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Settings updated"),
            new OA\Response(response: 401, description: "Not authenticated")
        ]
    )]
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $settings = $this->repo->findOneBy(['user' => $user]);

        if (!$settings) {
            $settings = new UserSettings();
            $settings->setUser($user);
            $this->em->persist($settings);
        }

        // Dynamisches Setzen der Felder
        $map = [
            'pop3Host', 'pop3Port', 'pop3Encryption',
            'imapHost', 'imapPort', 'imapEncryption',
            'smtpHost', 'smtpPort', 'smtpEncryption',
            'smtpUsername', 'smtpPassword',
            'emailAddress'
        ];

        foreach ($map as $field) {
            if (array_key_exists($field, $data)) {
                $setter = 'set' . ucfirst($field);
                $settings->$setter($data[$field]);
            }
        }

        $this->em->flush();

        return $this->json([
            'message' => 'Settings updated',
            'settings' => $this->serializeSettings($settings)
        ]);
    }


    private function serializeSettings(UserSettings $s): array
    {
        return [
            'id' => $s->getId(),
            'emailAddress' => $s->getEmailAddress(),

            'pop3' => [
                'host' => $s->getPop3Host(),
                'port' => $s->getPop3Port(),
                'encryption' => $s->getPop3Encryption(),
            ],

            'imap' => [
                'host' => $s->getImapHost(),
                'port' => $s->getImapPort(),
                'encryption' => $s->getImapEncryption(),
            ],

            'smtp' => [
                'host' => $s->getSmtpHost(),
                'port' => $s->getSmtpPort(),
                'encryption' => $s->getSmtpEncryption(),
                'username' => $s->getSmtpUsername(),
                'password' => $s->getSmtpPassword(),
            ]
        ];
    }
}

<?php
// src/Controller/PersonalAssistantController.php (Updated)

namespace App\Controller;

use App\DTO\AgentPromptRequest;
use App\Message\PersonalAssistantJob;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/api', name: 'api_')]
#[OA\Tag(name: 'AI Agent - Personal Assistant')]
class PersonalAssistantController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
        private MessageBusInterface $bus
    ) {}

    #[Route('/agent/personal', name: 'agent', methods: ['POST'])]
    #[OA\Post(
        summary: 'Personal Assistant AI Agent (Async)',
        description: 'Startet den Personal Assistant asynchron. Nutze /api/agent/status/{sessionId} für Updates.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AgentPromptRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Job erfolgreich in Warteschlange',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'queued'),
                        new OA\Property(property: 'sessionId', type: 'string', example: '01234567-89ab-cdef-0123-456789abcdef'),
                        new OA\Property(property: 'message', type: 'string')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Ungültige Anfrage')
        ]
    )]
    public function personalAssistent(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $prompt = $data['prompt'] ?? '';

        if (empty($prompt)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Kein Prompt angegeben',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Generiere Session-ID für Tracking
        $sessionId = Uuid::v4()->toRfc4122();

        $this->logger->info('Personal Assistant Job wird zur Warteschlange hinzugefügt', [
            'sessionId' => $sessionId,
            'prompt' => substr($prompt, 0, 100)
        ]);

        // Job zur Queue hinzufügen
        $this->bus->dispatch(new PersonalAssistantJob(
            prompt: $prompt,
            sessionId: $sessionId,
            userId: $this->getUser()?->getId()
        ));

        return $this->json([
            'status' => 'queued',
            'sessionId' => $sessionId,
            'message' => 'Job wurde erfolgreich zur Warteschlange hinzugefügt. Nutze /api/agent/status/' . $sessionId . ' für Updates.',
            'statusUrl' => '/api/agent/status/' . $sessionId
        ], Response::HTTP_OK);
    }

   
}
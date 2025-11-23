<?php

namespace App\Controller;

use App\Message\AiAgentJob;
use App\Service\AgentStatusService;
use OpenApi\Attributes as OA;
use Symfony\Component\Filesystem\Path;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AiAgentController extends AbstractController
{
    // Konstanten für das Verzeichnis und den gewünschten Prompt
    
    private const PROMPT_DIR = 'config/prompts';
    //private const TARGET_PROMPT = 'default_prompt.txt';
    private const TARGET_PROMPT = 'file_generator_prompt.txt';

    public function __construct(
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService,
        private ValidatorInterface $validator
    ) {}

    #[Route('/api/devAgent', name: 'api_devAgent_v2', methods: ['POST'])]
    #[OA\Post(
        path: '/api/devAgent',
        summary: 'Symfony Developing expert AI Agent (queued)',
        description: 'Enqueues the prompt to be processed asynchronously by workers.',
        tags: ['AI Agent']
    )]
    public function generateFile(
        Request $request,
        MessageBusInterface $bus
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $prompt = $data['prompt'] ?? null;
        
        // Optional: Session ID für Logging/Tracking
        $originSessionId = $data['sessionId'] ?? null;

        if (!$prompt) {
            return $this->json(['error' => 'Missing prompt'], 400);
        }

        // 1. Absoluten Pfad zur Prompt-Datei bestimmen
        $absolutePromptPath = Path::join(
            $this->getParameter('kernel.project_dir'), 
            self::PROMPT_DIR, 
            self::TARGET_PROMPT
        );
        // 2. Prompt-Inhalt lesen
        $systemPromptContent = file_get_contents($absolutePromptPath);

        // 3. Korrekte Option für Symfony AI setzen (überschreibt den Agent-Prompt)
        $agentOptions = [
            'system_prompt' => $systemPromptContent,
        ];
        

        // 3. Den Job mit Benutzer-Prompt, Session-ID und den Agent-Optionen dispatchen
        // Der AiAgentJob muss so gebaut werden, dass er diese Optionen entgegennimmt.
        $job = new AiAgentJob(
            prompt: $prompt, 
            originSessionId: $originSessionId, 
            options: $agentOptions // Übergabe der Prompt-Override-Optionen
        );

        $bus->dispatch($job);

        return $this->json([
            'status' => 'queued',
            'message' => 'Job wurde erfolgreich in die Warteschlange gelegt.'
        ]);
    }


    // OpenAPI Schema for AgentPromptRequest DTO
    #[OA\Schema(
        schema: 'AgentPromptRequest',
        required: ['prompt'],
        properties: [
            new OA\Property(property: 'prompt', type: 'string', example: 'Generate a deployment script for nginx'),
            new OA\Property(property: 'context', type: 'string', nullable: true),
            new OA\Property(property: 'options', type: 'object', nullable: true)
        ]
    )]
    private ?string $agentPromptRequestSchema = null; // Dummy property for schema definition
}

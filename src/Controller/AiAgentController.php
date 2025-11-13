<?php

namespace App\Controller;

use App\DTO\AgentPromptRequest;
use App\Message\AiAgentJob;
use App\Message\FrontendGeneratorJob;
use App\Service\AgentStatusService;
use App\Tool\DeployGeneratedCodeTool;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Message;

class AiAgentController extends AbstractController
{
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

        if (!$prompt) {
            return $this->json(['error' => 'Missing prompt'], 400);
        }

        $bus->dispatch(new AiAgentJob($prompt));

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

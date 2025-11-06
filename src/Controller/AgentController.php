<?php
namespace App\Controller;

use App\Agent\SelfDevelopingAgent;
use App\Repository\AgentTaskRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agent', name: 'api_agent_')]
class AgentController extends AbstractController
{
    public function __construct(
        private readonly SelfDevelopingAgent $agent,
        private readonly AgentTaskRepository $taskRepository
    ) {}

    /**
     * Agent-Prompt Endpoint - Startet selbst-entwickelnden Workflow
     */
    #[Route('/prompt', name: 'prompt', methods: ['POST'])]
    #[OA\Post(
        path: '/api/agent/prompt',
        summary: 'Agent-Aufgabe starten',
        description: 'Startet den selbst-entwickelnden AI-Agenten mit einem User-Prompt',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['prompt'],
                properties: [
                    new OA\Property(
                        property: 'prompt',
                        type: 'string',
                        example: 'Erstelle einen Service zum Abrufen von Wetterdaten'
                    ),
                    new OA\Property(
                        property: 'auto_approve',
                        type: 'boolean',
                        example: false,
                        description: 'Automatische Freigabe ohne Review (GEFÄHRLICH!)'
                    ),
                    new OA\Property(
                        property: 'config',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'test_mode', type: 'boolean'),
                            new OA\Property(property: 'max_iterations', type: 'integer')
                        ]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Task wurde erstellt und wird verarbeitet',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'processing'),
                        new OA\Property(property: 'task_id', type: 'string', example: 'task_abc123'),
                        new OA\Property(property: 'message', type: 'string')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Ungültige Anfrage'),
            new OA\Response(response: 500, description: 'Interner Fehler')
        ]
    )]
    public function prompt(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validierung
        if (!isset($data['prompt']) || empty($data['prompt'])) {
            return new JsonResponse([
                'error' => 'Prompt ist erforderlich'
            ], Response::HTTP_BAD_REQUEST);
        }

        $autoApprove = $data['auto_approve'] ?? false;
        $config = $data['config'] ?? [];

        try {
            // Task erstellen und Agent starten (asynchron)
            $taskId = $this->agent->processPrompt(
                prompt: $data['prompt'],
                autoApprove: $autoApprove,
                config: $config
            );

            return new JsonResponse([
                'status' => 'processing',
                'task_id' => $taskId,
                'message' => 'Agent analysiert die Anfrage...',
                'endpoints' => [
                    'status' => "/api/agent/task/{$taskId}",
                    'approve' => "/api/agent/task/{$taskId}/approve",
                    'reject' => "/api/agent/task/{$taskId}/reject"
                ]
            ], Response::HTTP_ACCEPTED);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Agent-Verarbeitung fehlgeschlagen',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Task-Status abrufen
     */
    #[Route('/task/{taskId}', name: 'task_status', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: "Task status list",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "tasks",
                    type: "array",
                    items: new OA\Items(
                        type: "object",
                        properties: [
                            new OA\Property(property: "id", type: "string"),
                            new OA\Property(property: "status", type: "string"),
                        ]
                    )
                )
            ]
        )
    )]
    public function getTaskStatus(string $taskId): JsonResponse
    {
        $task = $this->taskRepository->find($taskId);

        if (!$task) {
            return new JsonResponse([
                'error' => 'Task nicht gefunden'
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'task_id' => $task->getId(),
            'status' => $task->getStatus(),
            'created_at' => $task->getCreatedAt()->format('c'),
            'generated_files' => $task->getGeneratedFiles(),
            'test_results' => $task->getTestResults(),
            'actions' => $this->getAvailableActions($task)
        ]);
    }

    /**
     * Task-Approval (Code-Freigabe)
     */
    #[Route('/task/{taskId}/approve', name: 'task_approve', methods: ['POST'])]
    #[OA\Post(
        path: '/api/agent/task/{taskId}/approve',
        summary: 'Code-Freigabe erteilen',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approved', type: 'boolean', example: true),
                    new OA\Property(property: 'comment', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Code wurde implementiert'),
            new OA\Response(response: 400, description: 'Task nicht im Review-Status')
        ]
    )]
    public function approveTask(string $taskId, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($taskId);

        if (!$task) {
            return new JsonResponse(['error' => 'Task nicht gefunden'], 404);
        }

        if ($task->getStatus() !== 'awaiting_review') {
            return new JsonResponse([
                'error' => 'Task ist nicht im Review-Status',
                'current_status' => $task->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $approved = $data['approved'] ?? true;

        try {
            if ($approved) {
                // Code auf Host-System implementieren
                $result = $this->agent->implementGeneratedCode($task);

                return new JsonResponse([
                    'status' => 'completed',
                    'message' => 'Code wurde erfolgreich implementiert',
                    'files_created' => $result['files_created'],
                    'next_steps' => $result['next_steps']
                ]);
            } else {
                $task->setStatus('rejected');
                $this->taskRepository->save($task);

                return new JsonResponse([
                    'status' => 'rejected',
                    'message' => 'Task wurde abgelehnt'
                ]);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Implementierung fehlgeschlagen',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Task ablehnen
     */
    #[Route('/task/{taskId}/reject', name: 'task_reject', methods: ['POST'])]
    public function rejectTask(string $taskId): JsonResponse
    {
        $task = $this->taskRepository->find($taskId);

        if (!$task) {
            return new JsonResponse(['error' => 'Task nicht gefunden'], 404);
        }

        $task->setStatus('rejected');
        $this->taskRepository->save($task);

        return new JsonResponse([
            'status' => 'rejected',
            'message' => 'Task wurde abgelehnt und verworfen'
        ]);
    }

    /**
     * Health-Check für Agent-System
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
            'agent' => 'online',
            'executor' => $this->agent->checkExecutorHealth(),
            'timestamp' => date('c')
        ]);
    }

    /**
     * Verfügbare Aktionen für Task bestimmen
     */
    private function getAvailableActions($task): array
    {
        $actions = [];

        if ($task->getStatus() === 'awaiting_review') {
            $actions['approve'] = "POST /api/agent/task/{$task->getId()}/approve";
            $actions['reject'] = "POST /api/agent/task/{$task->getId()}/reject";
        }

        return $actions;
    }
}
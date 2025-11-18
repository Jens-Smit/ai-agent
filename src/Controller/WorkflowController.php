<?php
// src/Controller/WorkflowController.php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WorkflowRepository;
use App\Service\AgentStatusService;
use App\Service\ToolCapabilityChecker;
use App\Service\WorkflowEngine;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/workflow', name: 'api_workflow_')]
#[OA\Tag(name: 'Workflow Management')]
class WorkflowController extends AbstractController
{
    public function __construct(
        private WorkflowEngine $workflowEngine,
        private WorkflowRepository $workflowRepo,
        private ToolCapabilityChecker $capabilityChecker,
        private AgentStatusService $statusService,
        private LoggerInterface $logger
    ) {}

    /**
     * Erstellt und startet einen neuen Workflow
     */
    #[Route('/create', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Erstellt Workflow aus User-Intent',
        description: 'Der Personal Assistant analysiert den Intent, prüft Tool-Verfügbarkeit, plant den Workflow und startet die Ausführung.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['intent', 'sessionId'],
                properties: [
                    new OA\Property(property: 'intent', type: 'string', example: 'Such mir eine Wohnung in Berlin Mitte für 1500€'),
                    new OA\Property(property: 'sessionId', type: 'string', example: '01234567-89ab-cdef-0123-456789abcdef')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Workflow erstellt und gestartet',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'created'),
                        new OA\Property(property: 'workflow_id', type: 'integer'),
                        new OA\Property(property: 'session_id', type: 'string'),
                        new OA\Property(property: 'steps_count', type: 'integer'),
                        new OA\Property(
                            property: 'missing_tools',
                            type: 'array',
                            items: new OA\Items(type: 'string')
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Ungültige Anfrage')
        ]
    )]
    public function createWorkflow(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $intent = $data['intent'] ?? null;
        $sessionId = $data['sessionId'] ?? null;

        if (!$intent || !$sessionId) {
            return $this->json([
                'error' => 'Intent and sessionId are required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Creating workflow from intent', [
            'session' => $sessionId,
            'intent' => substr($intent, 0, 100)
        ]);

        try {
            // 1. Erkenne benötigte Capabilities
            $requiredCapabilities = $this->capabilityChecker->detectRequiredCapabilities($intent);
            
            $this->statusService->addStatus(
                $sessionId,
                sprintf('🔍 Erkannte Capabilities: %s', implode(', ', $requiredCapabilities))
            );

            // 2. Prüfe Tool-Verfügbarkeit
            $capabilityCheck = $this->capabilityChecker->checkCapabilities($requiredCapabilities);
            
            if (!empty($capabilityCheck['missing'])) {
                $this->statusService->addStatus(
                    $sessionId,
                    sprintf('⚠️ Fehlende Tools: %s', implode(', ', $capabilityCheck['missing']))
                );
                
                $this->statusService->addStatus(
                    $sessionId,
                    '🔧 Fordere fehlende Tools beim DevAgent an...'
                );
                
                // 3. Fordere fehlende Tools an
                $toolRequests = $this->capabilityChecker->requestMissingTools($capabilityCheck['missing']);
                
                foreach ($toolRequests as $toolName => $result) {
                    if ($result['status'] === 'success') {
                        $this->statusService->addStatus(
                            $sessionId,
                            sprintf('✅ Tool "%s" wurde angefordert', $toolName)
                        );
                    } else {
                        $this->statusService->addStatus(
                            $sessionId,
                            sprintf('❌ Tool "%s" konnte nicht angefordert werden', $toolName)
                        );
                    }
                }
            }

            // 4. Erstelle Workflow
            $this->statusService->addStatus($sessionId, '📋 Erstelle Workflow-Plan...');
            
            $workflow = $this->workflowEngine->createWorkflowFromIntent($intent, $sessionId);

            // 5. Starte Workflow
            $this->statusService->addStatus($sessionId, '🚀 Starte Workflow-Ausführung...');
            
            $this->workflowEngine->executeWorkflow($workflow);

            return $this->json([
                'status' => 'created',
                'workflow_id' => $workflow->getId(),
                'session_id' => $sessionId,
                'steps_count' => $workflow->getSteps()->count(),
                'missing_tools' => $capabilityCheck['missing'] ?? [],
                'message' => 'Workflow erstellt und wird ausgeführt. Nutze /api/workflow/status für Updates.'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Workflow creation failed', [
                'session' => $sessionId,
                'error' => $e->getMessage()
            ]);

            $this->statusService->addStatus(
                $sessionId,
                sprintf('❌ Fehler: %s', $e->getMessage())
            );

            return $this->json([
                'error' => 'Workflow creation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Gibt Workflow-Status zurück
     */
    #[Route('/status/{sessionId}', name: 'status', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt Workflow-Status',
        parameters: [
            new OA\Parameter(
                name: 'sessionId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Workflow-Status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'workflow_id', type: 'integer'),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'current_step', type: 'integer', nullable: true),
                        new OA\Property(property: 'total_steps', type: 'integer'),
                        new OA\Property(
                            property: 'steps',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'step_number', type: 'integer'),
                                    new OA\Property(property: 'description', type: 'string'),
                                    new OA\Property(property: 'status', type: 'string')
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Workflow nicht gefunden')
        ]
    )]
    public function getStatus(string $sessionId): JsonResponse
    {
        $workflow = $this->workflowRepo->findBySessionId($sessionId);

        if (!$workflow) {
            return $this->json([
                'error' => 'Workflow not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $steps[] = [
                'step_number' => $step->getStepNumber(),
                'type' => $step->getStepType(),
                'description' => $step->getDescription(),
                'status' => $step->getStatus(),
                'requires_confirmation' => $step->requiresConfirmation(),
                'result' => $step->getResult(),
                'error' => $step->getErrorMessage()
            ];
        }

        return $this->json([
            'workflow_id' => $workflow->getId(),
            'session_id' => $workflow->getSessionId(),
            'status' => $workflow->getStatus(),
            'current_step' => $workflow->getCurrentStep(),
            'total_steps' => $workflow->getSteps()->count(),
            'steps' => $steps,
            'created_at' => $workflow->getCreatedAt()->format('c'),
            'completed_at' => $workflow->getCompletedAt()?->format('c')
        ]);
    }

    /**
     * Bestätigt/Lehnt einen wartenden Workflow-Step ab
     */
    #[Route('/confirm/{workflowId}', name: 'confirm', methods: ['POST'])]
    #[OA\Post(
        summary: 'Bestätigt oder lehnt einen wartenden Step ab',
        parameters: [
            new OA\Parameter(
                name: 'workflowId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['confirmed'],
                properties: [
                    new OA\Property(property: 'confirmed', type: 'boolean', example: true)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Bestätigung verarbeitet'),
            new OA\Response(response: 400, description: 'Workflow wartet nicht auf Bestätigung'),
            new OA\Response(response: 404, description: 'Workflow nicht gefunden')
        ]
    )]
    public function confirmStep(int $workflowId, Request $request): JsonResponse
    {
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
            return $this->json([
                'error' => 'Workflow not found'
            ], Response::HTTP_NOT_FOUND);
        }

        if ($workflow->getStatus() !== 'waiting_confirmation') {
            return $this->json([
                'error' => 'Workflow is not waiting for confirmation',
                'current_status' => $workflow->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $confirmed = $data['confirmed'] ?? null;

        if ($confirmed === null) {
            return $this->json([
                'error' => 'confirmed parameter is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->workflowEngine->confirmStep($workflow, (bool) $confirmed);

            return $this->json([
                'status' => 'success',
                'confirmed' => (bool) $confirmed,
                'workflow_status' => $workflow->getStatus()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Step confirmation failed', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Confirmation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Listet alle Workflows auf (optional gefiltert)
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Listet Workflows auf',
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['created', 'running', 'waiting_confirmation', 'completed', 'failed', 'cancelled']
                )
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste der Workflows',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'workflows',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'session_id', type: 'string'),
                                    new OA\Property(property: 'status', type: 'string'),
                                    new OA\Property(property: 'created_at', type: 'string')
                                ]
                            )
                        )
                    ]
                )
            )
        ]
    )]
    public function listWorkflows(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $limit = (int) ($request->query->get('limit') ?? 20);

        $criteria = $status ? ['status' => $status] : [];
        
        $workflows = $this->workflowRepo->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit
        );

        $result = array_map(function($workflow) {
            return [
                'id' => $workflow->getId(),
                'session_id' => $workflow->getSessionId(),
                'user_intent' => substr($workflow->getUserIntent(), 0, 100),
                'status' => $workflow->getStatus(),
                'steps_count' => $workflow->getSteps()->count(),
                'current_step' => $workflow->getCurrentStep(),
                'created_at' => $workflow->getCreatedAt()->format('c'),
                'completed_at' => $workflow->getCompletedAt()?->format('c')
            ];
        }, $workflows);

        return $this->json([
            'workflows' => $result,
            'count' => count($result)
        ]);
    }

    /**
     * Gibt verfügbare Tools und Capabilities zurück
     */
    #[Route('/capabilities', name: 'capabilities', methods: ['GET'])]
    #[OA\Get(
        summary: 'Listet verfügbare Tools und Capabilities',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Verfügbare Capabilities',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'tools',
                            type: 'array',
                            items: new OA\Items(type: 'string')
                        ),
                        new OA\Property(
                            property: 'capabilities',
                            type: 'object'
                        )
                    ]
                )
            )
        ]
    )]
    public function getCapabilities(): JsonResponse
    {
        $tools = $this->capabilityChecker->getAvailableTools();

        return $this->json([
            'tools' => $tools,
            'tools_count' => count($tools),
            'capabilities' => [
                'apartment_search' => in_array('immobilien_search_tool', $tools),
                'calendar_management' => in_array('google_calendar_create_event', $tools),
                'email_sending' => in_array('gmail_send_tool', $tools),
                'web_scraping' => in_array('web_scraper', $tools),
                'pdf_generation' => in_array('PdfGenerator', $tools),
                'api_calling' => in_array('api_client', $tools),
            ]
        ]);
    }
}
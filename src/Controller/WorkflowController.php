<?php
// src/Controller/WorkflowController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserDocument;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use App\Service\ToolCapabilityChecker;
use App\Service\WorkflowEngine;
use App\Service\Workflow\WorkflowExecutor;
use App\Service\Workflow\WorkflowPlanner;
use Doctrine\ORM\EntityManagerInterface;
use FontLib\Table\Type\prep;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        private WorkflowPlanner $workflowPlanner,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private WorkflowExecutor $workflowExecutor,
        private ToolCapabilityChecker $capabilityChecker,
    ) {}

    /**
     * ✅ Erstellt einen Workflow-ENTWURF (nicht ausgeführt)
     */
    #[Route('/create', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Erstellt Workflow-Entwurf',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['intent', 'sessionId'],
                properties: [
                    new OA\Property(property: 'intent', type: 'string'),
                    new OA\Property(property: 'sessionId', type: 'string'),
                    new OA\Property(property: 'requiresApproval', type: 'boolean', default: true),
                    new OA\Property(property: 'saveAsTemplate', type: 'boolean', default: false),
                    new OA\Property(property: 'templateName', type: 'string')
                ]
            )
        )
    )]
    public function createWorkflow(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $intent = $data['intent'] ?? $data['user_intent'] ?? null;
        $sessionId = $data['sessionId'] ?? $data['session_id'] ?? null;
        $requiresApproval = $data['requiresApproval'] ?? true;
        $saveAsTemplate = $data['saveAsTemplate'] ?? false;
        $templateName = $data['templateName'] ?? null;

        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$sessionId) {
        // Generiere eine zufällige, eindeutige ID (z.B. UUID oder uniqid)
        $sessionId = uniqid('wf_', true); 
        }

        if (!$intent) {
        // Nur Intent ist jetzt ZWINGEND erforderlich
        return $this->json(['error' => 'Intent is required'], Response::HTTP_BAD_REQUEST);
        }
        try {
            // Erstelle Workflow-ENTWURF (nicht ausgeführt)
            $workflow = $this->workflowPlanner->createWorkflowFromIntent($intent, $sessionId);

            if ($requiresApproval) {
                $workflow->requireApproval();
            }

            if ($saveAsTemplate && $templateName) {
                $workflow->saveAsTemplate($templateName);
            }

            $this->em->flush();
            return $this->json(
                $workflow,
                Response::HTTP_CREATED,
                [],
                // 💡 WICHTIG: Serialsierungsgruppen hier anwenden
                ['groups' => ['workflow:read']] 
            );
            /*alt
            return $this->json([
                'status' => 'created',
                'workflow_id' => $workflow->getId(),
                'session_id' => $sessionId,
                'workflow_status' => $workflow->getStatus(),
                'steps_count' => $workflow->getSteps()->count(),
                'requires_approval' => $workflow->requireApproval(),
                'is_template' => $workflow->isTemplate(),
                'message' => 'Workflow-Entwurf erstellt. Rufe /approve auf um zu starten.'
            ]);
            */

        } catch (\Exception $e) {
            $this->logger->error('Workflow creation failed', [
                'session' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Workflow creation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * ✅ Genehmigt und STARTET Workflow
     */
    #[Route('/{workflowId}/approve', name: 'approve', methods: ['POST'])]
    #[OA\Post(
        summary: 'Genehmigt und startet Workflow',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ]
    )]
    public function approveWorkflow(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $workflow->approve($user->getId());
            $this->em->flush();

            // Starte Workflow JETZT
            $this->workflowEngine->executeWorkflow($workflow, $user);

            return $this->json([
                'status' => 'approved',
                'workflow_id' => $workflow->getId(),
                'message' => 'Workflow genehmigt und gestartet'
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Approval failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * ✅ Führt Workflow ERNEUT aus (Replay)
     */
    #[Route('/{workflowId}/execute', name: 'execute', methods: ['POST'])]
    #[OA\Post(
        summary: 'Führt Workflow erneut aus',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ]
    )]
    public function executeWorkflow(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$workflow->canExecute()) {
            return $this->json([
                'error' => 'Workflow cannot be executed',
                'reason' => 'Requires approval first'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->workflowEngine->executeWorkflow($workflow, $user);

            return $this->json([
                'status' => 'executing',
                'workflow_id' => $workflow->getId(),
                'execution_count' => $workflow->getExecutionCount()
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Execution failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * ✅ Konfiguriert Workflow-Schedule
     */
    #[Route('/{workflowId}/schedule', name: 'schedule', methods: ['POST'])]
    #[OA\Post(
        summary: 'Konfiguriert Workflow-Zeitplan',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['once', 'hourly', 'daily', 'weekly', 'biweekly', 'monthly']),
                    new OA\Property(property: 'config', type: 'object')
                ]
            )
        )
    )]
    public function scheduleWorkflow(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $type = $data['type'] ?? null;
        $config = $data['config'] ?? [];

        try {
            match($type) {
                'once' => $workflow->scheduleOnce(new \DateTimeImmutable($config['run_at'])),
                'hourly' => $workflow->scheduleHourly($config['minute'] ?? 0),
                'daily' => $workflow->scheduleDaily($config['time'] ?? '12:00'),
                'weekly' => $workflow->scheduleWeekly($config['day_of_week'], $config['time'] ?? '12:00'),
                'biweekly' => $workflow->scheduleBiweekly($config['day_of_week'], $config['time'] ?? '12:00'),
                'monthly' => $workflow->scheduleMonthly($config['day_of_month'], $config['time'] ?? '12:00'),
                default => throw new \InvalidArgumentException("Invalid schedule type: $type")
            };

            $this->em->flush();

            return $this->json([
                'status' => 'scheduled',
                'workflow_id' => $workflow->getId(),
                'schedule_type' => $workflow->getScheduleType(),
                'next_run_at' => $workflow->getNextRunAt()?->format('c')
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Scheduling failed',
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * ✅ Beantwortet User-Interaction
     */
    #[Route('/{workflowId}/resolve-interaction', name: 'resolve_interaction', methods: ['POST'])]
    #[OA\Post(
        summary: 'Beantwortet User-Interaction',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'resolution', type: 'object')
                ]
            )
        )
    )]
    public function resolveUserInteraction(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$workflow->hasUserInteraction()) {
            return $this->json([
                'error' => 'Workflow is not waiting for user input'
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $resolution = $data['resolution'] ?? [];

        try {
            $workflow->resolveUserInteraction($resolution);
            $this->em->flush();

            // Setze Workflow fort
            $this->workflowEngine->executeWorkflow($workflow, $user);

            return $this->json([
                'status' => 'resolved',
                'workflow_id' => $workflow->getId(),
                'message' => 'User interaction resolved, workflow continuing'
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Resolution failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    /**
     * ✅ NEU: Startet einen fehlgeschlagenen Workflow neu
     */
    #[Route('/{workflowId}/restart', name: 'restart', methods: ['POST'])]
    #[OA\Post(
        summary: 'Startet fehlgeschlagenen Workflow neu',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reset_failed_steps', type: 'boolean', default: true),
                    new OA\Property(property: 'from_step', type: 'integer', description: 'Optional: Ab welchem Step neu starten')
                ]
            )
        )
    )]
    public function restartWorkflow(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        // Prüfe ob Workflow neugestartet werden kann
        $allowedStatuses = ['failed', 'waiting_user_input', 'waiting_confirmation'];
        if (!in_array($workflow->getStatus(), $allowedStatuses)) {
            return $this->json([
                'error' => 'Workflow cannot be restarted',
                'current_status' => $workflow->getStatus(),
                'allowed_statuses' => $allowedStatuses
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $resetFailedSteps = $data['reset_failed_steps'] ?? true;
        $fromStep = $data['from_step'] ?? null;

        try {
            // ✅ Reset failed/pending Steps
            foreach ($workflow->getSteps() as $step) {
                // Reset alle Steps ab dem angegebenen Step
                if ($fromStep !== null && $step->getStepNumber() >= $fromStep) {
                    if ($step->getStatus() !== 'completed') {
                        $step->setStatus('pending');
                        $step->setErrorMessage(null);
                        $step->setResult(null);
                        
                        $this->logger->info('Reset step for restart', [
                            'workflow_id' => $workflowId,
                            'step' => $step->getStepNumber()
                        ]);
                    }
                }
                // Reset nur failed Steps
                elseif ($resetFailedSteps && $step->getStatus() === 'failed') {
                    $step->setStatus('pending');
                    $step->setErrorMessage(null);
                    
                    $this->logger->info('Reset failed step', [
                        'workflow_id' => $workflowId,
                        'step' => $step->getStepNumber()
                    ]);
                }
            }

            // Setze Workflow zurück
            $workflow->setStatus('approved'); // Bereit zur Ausführung
            $workflow->setCurrentStep(null);
            $this->em->flush();

            // ✅ WICHTIG: Starte Workflow mit User-Kontext
            $this->workflowEngine->executeWorkflow($workflow, $user);

            return $this->json([
                'status' => 'restarted',
                'workflow_id' => $workflow->getId(),
                'workflow_status' => $workflow->getStatus(),
                'message' => 'Workflow wurde neu gestartet'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to restart workflow', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to restart workflow',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * ✅ NEU: Injiziert User-Kontext in wartenden Workflow
     */
    #[Route('/{workflowId}/inject-user-context', name: 'inject_user_context', methods: ['POST'])]
    #[OA\Post(
        summary: 'Injiziert User-Kontext in wartenden Workflow',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ]
    )]
    public function injectUserContext(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json([
                'error' => 'Authentication required'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        // ✅ Prüfe ob Workflow User-Kontext braucht
        if ($workflow->getStatus() !== 'waiting_user_input') {
            return $this->json([
                'error' => 'Workflow is not waiting for user input',
                'current_status' => $workflow->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // ✅ Finde Step der User-Kontext braucht
            $pendingStep = null;
            foreach ($workflow->getSteps() as $step) {
                if ($step->getStatus() === 'pending_confirmation' || 
                    $step->getStatus() === 'failed') {
                    $emailDetails = $step->getEmailDetails();
                    if ($emailDetails && ($emailDetails['requires_user_context'] ?? false)) {
                        $pendingStep = $step;
                        break;
                    }
                }
            }

            if (!$pendingStep) {
                return $this->json([
                    'error' => 'No step found that requires user context'
                ], Response::HTTP_BAD_REQUEST);
            }

            // ✅ Aktualisiere E-Mail-Details mit User-ID
            $emailDetails = $pendingStep->getEmailDetails();
            $emailDetails['_user_id'] = $user->getId();
            $emailDetails['requires_user_context'] = false;
            $emailDetails['ready_to_send'] = true;
            
            // ✅ Lade vollständige Attachment-Details
            if (!empty($emailDetails['attachments'])) {
                $resolvedAttachments = [];
                foreach ($emailDetails['attachments'] as $attachment) {
                    if (isset($attachment['placeholder']) && $attachment['placeholder'] === true) {
                        // Lade echte Dokument-Details
                        $docId = $attachment['id'];
                        $document = $this->em->getRepository(\App\Entity\UserDocument::class)->find($docId);
                        
                        if ($document && $document->getUser() === $user) {
                            $fileSize = $document->getFileSize() ?? 0;
                            $resolvedAttachments[] = [
                                'id' => $document->getId(),
                                'filename' => $document->getOriginalFilename(),
                                'size' => $fileSize,
                                'size_human' => $this->formatBytes($document->getFileSize()),
                                'mime_type' => $document->getMimeType(),
                                'type' => $document->getDocumentType(),
                                'download_url' => sprintf('/api/documents/%d/download', $document->getId())
                            ];
                        }
                    } else {
                        $resolvedAttachments[] = $attachment;
                    }
                }
                $emailDetails['attachments'] = $resolvedAttachments;
                $emailDetails['attachment_count'] = count($resolvedAttachments);
            }

            $pendingStep->setEmailDetails($emailDetails);
            $pendingStep->setStatus('pending_confirmation');
            
            // ✅ Resolve User Interaction
            $workflow->resolveUserInteraction([
                'user_id' => $user->getId(),
                'action' => 'context_injected',
                'timestamp' => (new \DateTimeImmutable())->format('c')
            ]);
            
            $workflow->setStatus('waiting_confirmation');
            
            $this->em->flush();

            $this->logger->info('User context injected into workflow', [
                'workflow_id' => $workflowId,
                'user_id' => $user->getId(),
                'step_id' => $pendingStep->getId()
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'User-Kontext erfolgreich injiziert',
                'workflow_id' => $workflow->getId(),
                'workflow_status' => $workflow->getStatus(),
                'step_id' => $pendingStep->getId(),
                'email_details' => $emailDetails
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to inject user context', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to inject user context',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    /**
     * ✅ Listet User-Workflows
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function listWorkflows(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $status = $request->query->get('status');
        $limit = (int) ($request->query->get('limit') ?? 20);
        $criteria = ['user' => $user];

        if ($status) {
            $criteria['status'] = $status;
        }

        $workflows = $this->workflowRepo->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
            $limit
        );

        $result = array_map(fn($w) => [
            'id' => $w->getId(),
            'session_id' => $w->getSessionId(),
            'user_intent' => substr($w->getUserIntent(), 0, 100),
            'status' => $w->getStatus(),
            'steps_count' => $w->getSteps()->count(),
            'current_step' => $w->getCurrentStep(),
            'execution_count' => $w->getExecutionCount(),
            'is_scheduled' => $w->isScheduled(),
            'next_run_at' => $w->getNextRunAt()?->format('c'),
            'created_at' => $w->getCreatedAt()->format('c'),
            'completed_at' => $w->getCompletedAt()?->format('c')
        ], $workflows);

        return $this->json(['workflows' => $result, 'count' => count($result)]);
    }
    /**
     * ✅ Holt Workflow-Details
     */
    #[Route('/{workflowId}', name: 'get', methods: ['GET'])]
    public function getWorkflow(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $workflow->getId(),
            'status' => $workflow->getStatus(),
            'user_intent' => $workflow->getUserIntent(),
            'requires_approval' => $workflow->isRequiresApproval(),
            'is_approved' => $workflow->isApproved(),
            'is_scheduled' => $workflow->isScheduled(),
            'schedule_type' => $workflow->getScheduleType(),
            'next_run_at' => $workflow->getNextRunAt()?->format('c'),
            'execution_count' => $workflow->getExecutionCount(),
            'has_user_interaction' => $workflow->hasUserInteraction(),
            'user_interaction_message' => $workflow->getUserInteractionMessage(),
            'steps_count' => $workflow->getSteps()->count(),
            'created_at' => $workflow->getCreatedAt()->format('c')
        ]);
    }

    

    /**
     * Löscht einen Workflow
     */
    #[Route('/{workflowId}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Löscht einen Workflow',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ]
    )]
    public function deleteWorkflow(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->em->remove($workflow);
            $this->em->flush();

            $this->logger->info('Workflow deleted', ['workflow_id' => $workflowId]);

            return $this->json([
                'status' => 'deleted',
                'workflow_id' => $workflowId,
                'message' => 'Workflow erfolgreich gelöscht'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete workflow', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to delete workflow',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bestätigt/Lehnt wartenden Step ab
     */
    #[Route('/confirm/{workflowId}', name: 'confirm', methods: ['POST'])]
    #[OA\Post(
        summary: 'Bestätigt oder lehnt wartenden Step ab',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'confirmed', type: 'boolean')
                ]
            )
        )
    )]
    public function confirmStep(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => 'confirmed parameter is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->workflowEngine->confirmStep($workflow, (bool) $confirmed);

            return $this->json([
                'status' => 'success',
                'confirmed' => (bool) $confirmed,
                'workflow_status' => $workflow->getStatus()
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Confirmation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Holt Workflow-Status mit E-Mail-Details
     */
    #[Route('/status/{workflowId}', name: 'status', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt Workflow-Status mit E-Mail-Details',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ]
    )]
    public function getStatus(string $workflowId): JsonResponse
    {
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $stepData = [
                'id' => $step->getId(),
                'step_number' => $step->getStepNumber(),
                'type' => $step->getStepType(),
                'description' => $step->getDescription(),
                'status' => $step->getStatus(),
                'requires_confirmation' => $step->requiresConfirmation(),
                'result' => $step->getResult(),
                'error' => $step->getErrorMessage()
            ];

            if ($step->getEmailDetails()) {
                $stepData['email_details'] = $step->getEmailDetails();
            }

            $steps[] = $stepData;
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
     * Holt alle ausstehenden E-Mails eines Workflows
     */
    #[Route('/{workflowId}/pending-emails', name: 'pending_emails', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt alle ausstehenden E-Mails eines Workflows',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ]
    )]
    public function getPendingEmails(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $pendingEmails = [];

        foreach ($workflow->getSteps() as $step) {
            if ($step->getStatus() === 'pending_confirmation' &&
                in_array($step->getToolName(), ['send_email', 'SendMailTool']) &&
                $step->getEmailDetails()) {

                $details = $step->getEmailDetails();

                $pendingEmails[] = [
                    'step_id' => $step->getId(),
                    'step_number' => $step->getStepNumber(),
                    'recipient' => $details['recipient'],
                    'subject' => $details['subject'],
                    'body_preview' => $details['body_preview'] ?? mb_substr($details['body'], 0, 200),
                    'attachment_count' => $details['attachment_count'] ?? 0,
                    'created_at' => $details['created_at'] ?? null,
                    'status' => 'pending'
                ];
            }
        }

        return $this->json([
            'workflow_id' => $workflow->getId(),
            'workflow_status' => $workflow->getStatus(),
            'pending_count' => count($pendingEmails),
            'pending_emails' => $pendingEmails
        ]);
    }
    /**
     * Holt Details eines einzelnen Workflow-Steps
     */
    #[Route('/step/{stepId}', name: 'step_get', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt Details eines Workflow-Steps',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ]
    )]
    public function getStep(int $stepId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step || $step->getWorkflow()->getUser() !== $user) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $step->getId(),
            'step_number' => $step->getStepNumber(),
            'step_type' => $step->getStepType(),
            'description' => $step->getDescription(),
            'tool_name' => $step->getToolName(),
            'tool_parameters' => $step->getToolParameters(),
            'requires_confirmation' => $step->requiresConfirmation(),
            'status' => $step->getStatus(),
            'result' => $step->getResult(),
            'error_message' => $step->getErrorMessage(),
            'email_details' => $step->getEmailDetails(),
            'expected_output_format' => $step->getExpectedOutputFormat(),
            'completed_at' => $step->getCompletedAt()?->format('c'),
            'workflow_id' => $step->getWorkflow()->getId()
        ]);
    }

    /**
     * Aktualisiert einen Workflow-Step
     */
    #[Route('/step/{stepId}', name: 'step_update', methods: ['PATCH'])]
    #[OA\Patch(
        summary: 'Aktualisiert einen Workflow-Step',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'tool_name', type: 'string'),
                    new OA\Property(property: 'tool_parameters', type: 'object'),
                    new OA\Property(property: 'requires_confirmation', type: 'boolean'),
                    new OA\Property(property: 'expected_output_format', type: 'object')
                ]
            )
        )
    )]
    public function updateStep(int $stepId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step || $step->getWorkflow()->getUser() !== $user) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $workflow = $step->getWorkflow();

        // Nur Steps in draft/waiting_confirmation Status dürfen bearbeitet werden
        if (!in_array($workflow->getStatus(), ['draft', 'waiting_confirmation', 'failed'])) {
            return $this->json([
                'error' => 'Cannot edit step in workflow with status: ' . $workflow->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        try {
            if (isset($data['description'])) {
                $step->setDescription($data['description']);
            }

            if (isset($data['tool_name'])) {
                $step->setToolName($data['tool_name']);
            }

            if (isset($data['tool_parameters'])) {
                $step->setToolParameters($data['tool_parameters']);
            }

            if (isset($data['requires_confirmation'])) {
                $step->setRequiresConfirmation((bool) $data['requires_confirmation']);
            }

            if (isset($data['expected_output_format'])) {
                $step->setExpectedOutputFormat($data['expected_output_format']);
            }

            $this->em->flush();

            $this->logger->info('Workflow step updated', [
                'step_id' => $stepId,
                'workflow_id' => $workflow->getId(),
                'updated_by' => $user->getId()
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Step successfully updated',
                'step' => [
                    'id' => $step->getId(),
                    'step_number' => $step->getStepNumber(),
                    'description' => $step->getDescription(),
                    'tool_name' => $step->getToolName(),
                    'tool_parameters' => $step->getToolParameters()
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update step', [
                'step_id' => $stepId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to update step',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Löscht einen Workflow-Step
     */
    #[Route('/step/{stepId}', name: 'step_delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Löscht einen Workflow-Step',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ]
    )]
    public function deleteStep(int $stepId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step || $step->getWorkflow()->getUser() !== $user) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $workflow = $step->getWorkflow();

        // Nur Steps in draft Status dürfen gelöscht werden
        if ($workflow->getStatus() !== 'draft') {
            return $this->json([
                'error' => 'Cannot delete step in workflow with status: ' . $workflow->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verhindere Löschen wenn nur noch 1 Step übrig
        if ($workflow->getSteps()->count() <= 1) {
            return $this->json([
                'error' => 'Cannot delete last remaining step'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $stepNumber = $step->getStepNumber();
            $workflowId = $workflow->getId();

            $this->em->remove($step);
            $this->em->flush();

            // Renummeriere verbleibende Steps
            $remainingSteps = $workflow->getSteps()->toArray();
            usort($remainingSteps, fn($a, $b) => $a->getStepNumber() <=> $b->getStepNumber());

            $newNumber = 1;
            foreach ($remainingSteps as $s) {
                $s->setStepNumber($newNumber++);
            }

            $this->em->flush();

            $this->logger->info('Workflow step deleted', [
                'step_id' => $stepId,
                'step_number' => $stepNumber,
                'workflow_id' => $workflowId
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Step successfully deleted',
                'remaining_steps' => $workflow->getSteps()->count()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete step', [
                'step_id' => $stepId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to delete step',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Fügt einen neuen Step zu einem Workflow hinzu
     */
    #[Route('/{workflowId}/steps', name: 'step_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Fügt einen neuen Step zu einem Workflow hinzu',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                required: ['step_type', 'description'],
                properties: [
                    new OA\Property(property: 'step_type', type: 'string', enum: ['tool_call', 'analysis', 'decision', 'notification']),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'tool_name', type: 'string'),
                    new OA\Property(property: 'tool_parameters', type: 'object'),
                    new OA\Property(property: 'requires_confirmation', type: 'boolean', default: false),
                    new OA\Property(property: 'expected_output_format', type: 'object'),
                    new OA\Property(property: 'insert_after', type: 'integer', description: 'Step number nach dem eingefügt werden soll (optional)')
                ]
            )
        )
    )]
    public function createStep(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        // Nur in draft Status dürfen Steps hinzugefügt werden
        if ($workflow->getStatus() !== 'draft') {
            return $this->json([
                'error' => 'Cannot add step to workflow with status: ' . $workflow->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['step_type']) || !isset($data['description'])) {
            return $this->json([
                'error' => 'Missing required fields: step_type, description'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $step = new WorkflowStep();
            $step->setWorkflow($workflow);
            $step->setStepType($data['step_type']);
            $step->setDescription($data['description']);
            $step->setStatus('pending');

            if (isset($data['tool_name'])) {
                $step->setToolName($data['tool_name']);
            }

            if (isset($data['tool_parameters'])) {
                $step->setToolParameters($data['tool_parameters']);
            }

            if (isset($data['requires_confirmation'])) {
                $step->setRequiresConfirmation((bool) $data['requires_confirmation']);
            }

            if (isset($data['expected_output_format'])) {
                $step->setExpectedOutputFormat($data['expected_output_format']);
            }

            // Bestimme Step Number
            $insertAfter = $data['insert_after'] ?? null;
            
            if ($insertAfter !== null) {
                // Verschiebe alle nachfolgenden Steps um 1
                $stepsToMove = $workflow->getSteps()->filter(
                    fn($s) => $s->getStepNumber() > $insertAfter
                )->toArray();

                foreach ($stepsToMove as $s) {
                    $s->setStepNumber($s->getStepNumber() + 1);
                }

                $step->setStepNumber($insertAfter + 1);
            } else {
                // Füge am Ende hinzu
                $maxStepNumber = 0;
                foreach ($workflow->getSteps() as $s) {
                    $maxStepNumber = max($maxStepNumber, $s->getStepNumber());
                }
                $step->setStepNumber($maxStepNumber + 1);
            }

            $this->em->persist($step);
            $this->em->flush();

            $this->logger->info('Workflow step created', [
                'step_id' => $step->getId(),
                'step_number' => $step->getStepNumber(),
                'workflow_id' => $workflowId
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Step successfully created',
                'step' => [
                    'id' => $step->getId(),
                    'step_number' => $step->getStepNumber(),
                    'step_type' => $step->getStepType(),
                    'description' => $step->getDescription(),
                    'tool_name' => $step->getToolName(),
                    'status' => $step->getStatus()
                ]
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create step', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to create step',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Ändert die Reihenfolge von Workflow-Steps
     */
    #[Route('/{workflowId}/steps/reorder', name: 'steps_reorder', methods: ['POST'])]
    #[OA\Post(
        summary: 'Ändert die Reihenfolge von Workflow-Steps',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true)
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                required: ['step_order'],
                properties: [
                    new OA\Property(
                        property: 'step_order',
                        type: 'array',
                        items: new OA\Items(type: 'integer'),
                        description: 'Array von Step-IDs in gewünschter Reihenfolge'
                    )
                ]
            )
        )
    )]
    public function reorderSteps(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        if ($workflow->getStatus() !== 'draft') {
            return $this->json([
                'error' => 'Cannot reorder steps in workflow with status: ' . $workflow->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['step_order']) || !is_array($data['step_order'])) {
            return $this->json([
                'error' => 'Missing or invalid step_order array'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $stepOrder = $data['step_order'];
            $steps = $workflow->getSteps();

            // Validiere dass alle Steps vorhanden sind
            if (count($stepOrder) !== $steps->count()) {
                return $this->json([
                    'error' => 'Step count mismatch'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Mappe Step-IDs zu Step-Objekten
            $stepMap = [];
            foreach ($steps as $step) {
                $stepMap[$step->getId()] = $step;
            }

            // Validiere dass alle IDs gültig sind
            foreach ($stepOrder as $stepId) {
                if (!isset($stepMap[$stepId])) {
                    return $this->json([
                        'error' => 'Invalid step ID: ' . $stepId
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Setze neue Reihenfolge
            $newNumber = 1;
            foreach ($stepOrder as $stepId) {
                $stepMap[$stepId]->setStepNumber($newNumber++);
            }

            $this->em->flush();

            $this->logger->info('Workflow steps reordered', [
                'workflow_id' => $workflowId,
                'new_order' => $stepOrder
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Steps successfully reordered'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to reorder steps', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to reorder steps',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    /**
     * ✅ VERBESSERT: Holt E-Mail-Details auch für incomplete emails
     */
    #[Route('/step/{stepId}/email', name: 'step_email_details', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt vollständige E-Mail-Details',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ]
    )]
    public function getStepEmailDetails(int $stepId): JsonResponse
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $emailDetails = $step->getEmailDetails();

        if (!$emailDetails) {
            return $this->json(['error' => 'No email details available'], Response::HTTP_NOT_FOUND);
        }

        // ✅ NEU: Generiere Preview-URLs auch für Placeholder-Attachments
        if (isset($emailDetails['attachments'])) {
            foreach ($emailDetails['attachments'] as &$attachment) {
                if (!isset($attachment['preview_url'])) {
                    $attachment['preview_url'] = sprintf(
                        '/api/workflow/step/%d/attachment/%s/preview',
                        $stepId,
                        $attachment['id']
                    );
                }
                if (!isset($attachment['download_url'])) {
                    $attachment['download_url'] = sprintf(
                        '/api/workflow/step/%d/attachment/%s/download',
                        $stepId,
                        $attachment['id']
                    );
                }
            }
        }

        // ✅ Status-Checks
        $canSend = $step->getStatus() === 'pending_confirmation' && 
                ($emailDetails['ready_to_send'] ?? false);
        
        $needsUserContext = $emailDetails['requires_user_context'] ?? false;

        return $this->json([
            'step_id' => $stepId,
            'step_number' => $step->getStepNumber(),
            'status' => $step->getStatus(),
            'email_details' => $emailDetails,
            'can_send' => $canSend,
            'can_reject' => $step->getStatus() === 'pending_confirmation',
            'needs_user_context' => $needsUserContext,
            'actions_available' => [
                'send' => $canSend ? '/api/workflow/step/' . $stepId . '/send-email' : null,
                'reject' => $step->getStatus() === 'pending_confirmation' ? '/api/workflow/step/' . $stepId . '/reject-email' : null,
                'inject_context' => $needsUserContext ? '/api/workflow/' . $step->getWorkflow()->getId() . '/inject-user-context' : null
            ]
        ]);
    }

    /**
     * Hilfsmethode für Bytes-Formatierung
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Alternative: $pow = ($bytes > 0) ? floor(log($bytes, 1024)) : 0; 
        
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Holt vollständigen E-Mail-Body
     */
    #[Route('/step/{stepId}/email-body', name: 'step_email_body', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt vollständigen E-Mail-Body',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ]
    )]
    public function getStepEmailBody(int $stepId): JsonResponse
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $emailDetails = $step->getEmailDetails();

        if (!$emailDetails) {
            return $this->json(['error' => 'No email details available'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'step_id' => $stepId,
            'recipient' => $emailDetails['recipient'],
            'subject' => $emailDetails['subject'],
            'body' => $emailDetails['body'],
            'body_html' => nl2br(htmlspecialchars($emailDetails['body'], ENT_QUOTES, 'UTF-8')),
            'attachments' => $emailDetails['attachments']
        ]);
    }

    /**
     * Sendet ausstehende E-Mail
     */
    #[Route('/step/{stepId}/send-email', name: 'send_email', methods: ['POST'])]
    #[OA\Post(
        summary: 'Sendet ausstehende E-Mail',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ]
    )]
    public function sendEmail(int $stepId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        // ✅ Security Check: User muss Owner des Workflows sein
        if ($step->getWorkflow()->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        if ($user->getUserSettings()->getEmailAddress() === null) {
            return $this->json([
                'error' => 'Keine Absender-E-Mail-Adresse konfiguriert',
                'message' => 'Bitte konfigurieren Sie eine Absender-E-Mail-Adresse in Ihren Benutzereinstellungen, bevor Sie E-Mails senden.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $workflow = $step->getWorkflow();

        try {
            // ✅ WICHTIG: User-Kontext übergeben
            $this->workflowExecutor->confirmAndSendEmail($workflow, $step, $user);

            return $this->json([
                'status' => 'success',
                'message' => 'Email sent successfully',
                'step_id' => $stepId,
                'step_status' => 'completed',
                'workflow_status' => $workflow->getStatus(),
                'sent_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send email', [
                'step_id' => $stepId,
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to send email',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lehnt E-Mail ab
     */
    #[Route('/step/{stepId}/reject-email', name: 'reject_email', methods: ['POST'])]
    #[OA\Post(
        summary: 'Lehnt ausstehende E-Mail ab',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ]
    )]
    public function rejectEmail(int $stepId): JsonResponse
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        

        $workflow = $step->getWorkflow();

        try {
            $this->workflowExecutor->rejectEmail($workflow, $step);

            return $this->json([
                'status' => 'success',
                'message' => 'Email rejected',
                'step_id' => $stepId,
                'step_status' => 'rejected',
                'workflow_status' => $workflow->getStatus()
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to reject email',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Vorschau eines Anhangs
     */
    #[Route('/step/{stepId}/attachment/{attachmentId}/preview', name: 'step_attachment_preview', methods: ['GET'])]
    #[OA\Get(
        summary: 'Zeigt E-Mail-Anhang zur Vorschau',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true),
            new OA\Parameter(name: 'attachmentId', in: 'path', required: true)
        ]
    )]
    public function previewAttachment(int $stepId, int $attachmentId): Response
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            throw $this->createNotFoundException('Step not found');
        }

        $document = $this->em->getRepository(UserDocument::class)->find($attachmentId);

        if (!$document) {
            throw $this->createNotFoundException('Document not found');
        }

        $filePath = $document->getFullPath();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found');
        }

        return new BinaryFileResponse($filePath);
    }

    /**
     * Download eines Anhangs
     */
    #[Route('/step/{stepId}/attachment/{attachmentId}/download', name: 'step_attachment_download', methods: ['GET'])]
    #[OA\Get(
        summary: 'Lädt E-Mail-Anhang herunter',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true),
            new OA\Parameter(name: 'attachmentId', in: 'path', required: true)
        ]
    )]
    public function downloadAttachment(int $stepId, int $attachmentId): Response
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            throw $this->createNotFoundException('Step not found');
        }

        $document = $this->em->getRepository(UserDocument::class)->find($attachmentId);

        if (!$document) {
            throw $this->createNotFoundException('Document not found');
        }

        $filePath = $document->getFullPath();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            'attachment',
            $document->getOriginalFilename()
        );

        return $response;
    }

    /**
     * Gibt verfügbare Tools zurück
     */
    #[Route('/capabilities', name: 'capabilities', methods: ['GET'])]
    #[OA\Get(summary: 'Listet verfügbare Tools und Capabilities')]
    public function getCapabilities(): JsonResponse
    {
        // HINWEIS: Stelle sicher, dass ToolCapabilityChecker eine Methode getAvailableTools() hat,
        // die ein einfaches Array von Strings (Tool-Namen) zurückgibt.
        // Falls im Refactoring gelöscht, bitte dort wieder hinzufügen:
        // public function getAvailableTools(): array { return array_column($this->availableToolDefinitions, 'name'); }
        
        $tools = [];
        if (method_exists($this->capabilityChecker, 'getAvailableTools')) {
            $tools = $this->capabilityChecker->getAvailableTools();
        } elseif (method_exists($this->capabilityChecker, 'getAvailableToolDefinitions')) {
             // Fallback, falls du Getter geändert hast
             $defs = $this->capabilityChecker->getAvailableToolDefinitions();
             $tools = array_column($defs, 'name');
        }

        return $this->json([
            'tools' => $tools,
            'tools_count' => count($tools),
            // Mapping für das Frontend beibehalten (Legacy-Support)
            'capabilities' => [
                'apartment_search' => $this->hasToolLike($tools, ['immobilien', 'search', 'apartment']),
                'calendar_management' => $this->hasToolLike($tools, ['calendar', 'termin', 'schedule']),
                'email_sending' => $this->hasToolLike($tools, ['mail', 'send']),
                'web_scraping' => $this->hasToolLike($tools, ['scrape', 'crawl']),
                'pdf_generation' => $this->hasToolLike($tools, ['pdf']),
                'api_calling' => $this->hasToolLike($tools, ['api', 'client']),
            ]
        ]);
    }

    /**
     * Hilfsmethode für Tool-Matching
     */
    private function hasToolLike(array $availableTools, array $keywords): bool
    {
        foreach ($availableTools as $toolName) {
            foreach ($keywords as $keyword) {
                if (stripos($toolName, $keyword) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
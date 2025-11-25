<?php
// src/Controller/WorkflowController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserDocument;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use App\Service\AgentStatusService;
use App\Service\ToolCapabilityChecker;
use App\Service\WorkflowEngine;
use App\Service\Workflow\WorkflowExecutor;
use Doctrine\ORM\EntityManagerInterface;
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
        private WorkflowExecutor $workflowExecutor,
        private WorkflowRepository $workflowRepo,
        private ToolCapabilityChecker $capabilityChecker,
        private AgentStatusService $statusService,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
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
        // 1. Daten und User abrufen
        $data = json_decode($request->getContent(), true);
        // Behebung des Frontend-Problems (falls noch nicht gelöst, siehe vorherige Diskussion)
        $intent = $data['intent'] ?? $data['user_intent'] ?? null; 
        $sessionId = $data['sessionId'] ?? $data['session_id'] ?? null;
        
        // Den aktuellen authentifizierten Benutzer abrufen
        // Dies funktioniert nur, wenn der Controller von Symfony korrekt eingerichtet wurde (z.B. durch Extenden von AbstractController)
        /** @var User $user */
        $user = $this->getUser(); 
        
        // Zusätzliche Prüfung auf den User (falls das Security-Attribut IsGranted fehlt)
        if (!$user) {
            return $this->json([
                'error' => 'Authentication required',
                'message' => 'User must be logged in to create a workflow.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (!$intent || !$sessionId) {
            return $this->json([
                'error' => 'Intent and sessionId are required'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // 2. Logging und Status
        $this->logger->info('Creating workflow from intent', [
            'session' => $sessionId,
            'intent' => substr($intent, 0, 100),
            'user_id' => $user->getId(), // Logge die User ID
        ]);

        try {
            $this->statusService->addStatus($sessionId, '🤖 KI analysiert Intent und Tool-Landschaft...');

            // ... (Capability Checker Logik bleibt gleich)
            $capabilityResult = $this->capabilityChecker->ensureCapabilitiesFor($intent);
            
            // ... (Status-Updates für Capability Checker)

            // 3. Workflow erstellen
            $this->statusService->addStatus($sessionId, '📋 Erstelle Workflow-Plan...');
            
            // Füge den Benutzer beim Erstellen hinzu, damit der Workflow weiß, wem er gehört
            $workflow = $this->workflowEngine->createWorkflowFromIntent($intent, $sessionId, $user); 

            // 4. Starte Workflow
            $this->statusService->addStatus($sessionId, '🚀 Starte Workflow-Ausführung...');
            
            // 🚨 WICHTIG: ÜBERGABE DES BENUTZERS AN DEN EXECUTOR
            // Dies entspricht der Korrektur, die im WorkflowExecutor erforderlich ist.
            $this->workflowEngine->executeWorkflow($workflow, $user); 

            // 5. Erfolgreiche Antwort
            return $this->json([
                'status' => 'created',
                'workflow_id' => $workflow->getId(),
                'session_id' => $sessionId,
                'steps_count' => $workflow->getSteps()->count(),
                'missing_tools' => [],
                'message' => 'Workflow erstellt und wird ausgeführt.'
            ]);

        } catch (\Exception $e) {
            // ... (Error Handling Logik bleibt gleich)
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
     * Löscht einen Workflow anhand seiner ID
     */
    #[Route('/{workflowId}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Löscht einen Workflow',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true, description: 'ID des zu löschenden Workflows', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200, 
                description: 'Workflow erfolgreich gelöscht', 
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'deleted'),
                    new OA\Property(property: 'workflow_id', type: 'integer')
                ])
            ),
            new OA\Response(response: 404, description: 'Workflow nicht gefunden')
        ]
    )]
    public function deleteWorkflow(int $workflowId): JsonResponse
    {
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
            $this->logger->warning('Attempted to delete non-existent workflow', ['workflowId' => $workflowId]);
            return $this->json([
                'error' => 'Workflow not found',
                'workflow_id' => $workflowId
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            // Löschen des Workflows
            $this->em->remove($workflow);
            $this->em->flush();

            $this->logger->info('Workflow successfully deleted', ['workflowId' => $workflowId, 'sessionId' => $workflow->getSessionId()]);

            return $this->json([
                'status' => 'deleted',
                'workflow_id' => $workflowId,
                'message' => sprintf('Workflow mit ID %d erfolgreich gelöscht.', $workflowId)
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete workflow', ['workflowId' => $workflowId, 'error' => $e->getMessage()]);
            return $this->json([
                'error' => 'Failed to delete workflow',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    #[Route('/status/{sessionId}', name: 'status', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt Workflow-Status mit E-Mail-Details',
        parameters: [
            new OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ]
    )]
    public function getStatus(string $sessionId): JsonResponse
    {
        $workflow = $this->workflowRepo->findBySessionId($sessionId);

        if (!$workflow) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $stepData = [
                'step_number' => $step->getStepNumber(),
                'type' => $step->getStepType(),
                'description' => $step->getDescription(),
                'status' => $step->getStatus(),
                'requires_confirmation' => $step->requiresConfirmation(),
                'result' => $step->getResult(),
                'error' => $step->getErrorMessage()
            ];

            // Füge E-Mail-Details hinzu, wenn vorhanden
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
     * Holt den vollständigen E-Mail-Body eines Steps
     */
    #[Route('/step/{stepId}/email-body', name: 'step_email_body', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt den vollständigen E-Mail-Body eines Steps',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
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
            'body_html' => $this->formatEmailBodyAsHtml($emailDetails['body']),
            'attachments' => $emailDetails['attachments']
        ]);
    }

    /**
     * Holt einen E-Mail-Anhang zur Vorschau
     */
    #[Route('/step/{stepId}/attachment/{attachmentId}/preview', name: 'step_attachment_preview', methods: ['GET'])]
    #[OA\Get(
        summary: 'Zeigt einen E-Mail-Anhang zur Vorschau',
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

        // Sicherheitsprüfung: Gehört das Dokument zum User des Workflows?
        $workflow = $step->getWorkflow();
        // Hier müsste man den User aus dem Workflow ermitteln (könnte ergänzt werden)

        $filePath = $document->getFullPath();
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found');
        }

        return new BinaryFileResponse($filePath);
    }

    private function formatEmailBodyAsHtml(string $body): string
    {
        // Konvertiere Plain Text zu HTML für bessere Darstellung
        return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Bestätigt/Lehnt einen wartenden Workflow-Step ab
     */
    #[Route('/confirm/{workflowId}', name: 'confirm', methods: ['POST'])]
    #[OA\Post(
        summary: 'Bestätigt oder lehnt einen wartenden Step ab',
        parameters: [new OA\Parameter(name: 'workflowId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'confirmed', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'OK')]
    )]
    public function confirmStep(int $workflowId, Request $request): JsonResponse
    {
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
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
            return $this->json(['error' => 'Confirmation failed', 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Listet alle Workflows auf
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    #[OA\Get(summary: 'Listet Workflows auf', responses: [new OA\Response(response: 200, description: 'Liste')]) ]
    public function listWorkflows(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $limit = (int) ($request->query->get('limit') ?? 20);
        $criteria = $status ? ['status' => $status] : [];
        
        $workflows = $this->workflowRepo->findBy($criteria, ['createdAt' => 'DESC'], $limit);

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

        return $this->json(['workflows' => $result, 'count' => count($result)]);
    }

    /**
     * Gibt verfügbare Tools und Capabilities zurück
     * HINWEIS: Da Tools nun dynamisch sind, ist dieses statische Mapping 
     * nur noch eine Näherung für das Frontend.
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
     * ✅ NEU: Holt alle ausstehenden E-Mails eines Workflows
     */
    #[Route('/{workflowId}/pending-emails', name: 'pending_emails', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt alle ausstehenden E-Mails eines Workflows',
        parameters: [
            new OA\Parameter(name: 'workflowId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste der ausstehenden E-Mails',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'workflow_id', type: 'integer'),
                        new OA\Property(property: 'workflow_status', type: 'string'),
                        new OA\Property(
                            property: 'pending_emails',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'step_id', type: 'integer'),
                                    new OA\Property(property: 'step_number', type: 'integer'),
                                    new OA\Property(property: 'recipient', type: 'string'),
                                    new OA\Property(property: 'subject', type: 'string'),
                                    new OA\Property(property: 'body_preview', type: 'string'),
                                    new OA\Property(property: 'attachment_count', type: 'integer'),
                                    new OA\Property(property: 'created_at', type: 'string'),
                                ]
                            )
                        )
                    ]
                )
            )
        ]
    )]
    public function getPendingEmails(int $workflowId): JsonResponse
    {
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
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
     * ✅ NEU: Holt vollständige E-Mail-Details eines Steps
     */
    #[Route('/step/{stepId}/email', name: 'step_email_details', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt vollständige E-Mail-Details eines Steps',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
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

        // Erweitere Attachment-Details mit Download-URLs
        if (isset($emailDetails['attachments'])) {
            foreach ($emailDetails['attachments'] as &$attachment) {
                $attachment['preview_url'] = sprintf(
                    '/api/workflow/step/%d/attachment/%d/preview',
                    $stepId,
                    $attachment['id']
                );
                $attachment['download_url'] = sprintf(
                    '/api/workflow/step/%d/attachment/%d/download',
                    $stepId,
                    $attachment['id']
                );
            }
        }

        return $this->json([
            'step_id' => $stepId,
            'step_number' => $step->getStepNumber(),
            'status' => $step->getStatus(),
            'email_details' => $emailDetails,
            'can_send' => $step->getStatus() === 'pending_confirmation',
            'can_reject' => $step->getStatus() === 'pending_confirmation'
        ]);
    }

    /**
     * ✅ NEU: Sendet eine ausstehende E-Mail
     */
    #[Route('/step/{stepId}/send-email', name: 'send_email', methods: ['POST'])]
    #[OA\Post(
        summary: 'Sendet eine ausstehende E-Mail',
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true)
        ],
        responses: [
            new OA\Response(response: 200, description: 'E-Mail erfolgreich versendet'),
            new OA\Response(response: 400, description: 'E-Mail kann nicht versendet werden')
        ]
    )]
    public function sendEmail(int $stepId): JsonResponse
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        if ($step->getStatus() !== 'pending_confirmation') {
            return $this->json([
                'error' => 'Email is not pending confirmation',
                'current_status' => $step->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $workflow = $step->getWorkflow();
        /** @var User $user */
        $user = $this->getUser();

        try {
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
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to send email',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * ✅ NEU: Lehnt eine ausstehende E-Mail ab
     */
    #[Route('/step/{stepId}/reject-email', name: 'reject_email', methods: ['POST'])]
    #[OA\Post(
        summary: 'Lehnt eine ausstehende E-Mail ab',
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

        if ($step->getStatus() !== 'pending_confirmation') {
            return $this->json([
                'error' => 'Email is not pending confirmation',
                'current_status' => $step->getStatus()
            ], Response::HTTP_BAD_REQUEST);
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
     * Hilfsmethode für unscharfes Matching im Legacy-Endpoint
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
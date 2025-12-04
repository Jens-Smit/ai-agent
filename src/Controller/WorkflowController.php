<?php
// src/Controller/WorkflowController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use App\Service\Workflow\WorkflowPlanner;
use App\Service\Workflow\WorkflowExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;

/**
 * Workflow Controller - Vereinfacht
 * 
 * Ruft direkt WorkflowPlanner und WorkflowExecutor auf
 * Keine WorkflowEngine mehr!
 */
#[Route('/api/workflow', name: 'api_workflow_')]
#[OA\Tag(name: 'Workflow Management')]
class WorkflowController extends AbstractController
{
    public function __construct(
        private WorkflowPlanner $planner,
        private WorkflowExecutor $executor,
        private WorkflowRepository $workflowRepo,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    // ========================================
    // WORKFLOW LIFECYCLE
    // ========================================

    /**
     * 1. Erstellt Workflow-Entwurf (nicht ausgeführt)
     */
    #[Route('/create', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Erstellt Workflow-Entwurf',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['intent'],
                properties: [
                    new OA\Property(property: 'intent', type: 'string', example: 'Bewerbe dich als Entwickler in Lübeck'),
                    new OA\Property(property: 'sessionId', type: 'string', example: 'wf_abc123')
                ]
            )
        )
    )]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $intent = $data['intent'] ?? null;
        $sessionId = $data['sessionId'] ?? uniqid('wf_', true);

        if (!$intent) {
            return $this->json(['error' => 'Intent is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Direkt Planner aufrufen
            $workflow = $this->planner->createWorkflow($intent, $sessionId);

            return $this->json([
                'status' => 'created',
                'workflow_id' => $workflow->getId(),
                'session_id' => $sessionId,
                'workflow_status' => $workflow->getStatus(),
                'steps_count' => $workflow->getSteps()->count(),
                'requires_approval' => true,
                'message' => 'Workflow erstellt. Rufe /approve auf um zu starten.'
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            $this->logger->error('Workflow creation failed', [
                'intent' => $intent,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Workflow creation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 2. Genehmigt und STARTET Workflow
     */
    #[Route('/{workflowId}/approve', name: 'approve', methods: ['POST'])]
    #[OA\Post(
        summary: 'Genehmigt und startet Workflow',
        parameters: [new OA\Parameter(name: 'workflowId', in: 'path', required: true)]
    )]
    public function approve(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            // Genehmige
            $workflow->approve($user->getId());
            $this->em->flush();

            // Direkt Executor aufrufen
            $this->executor->executeWorkflow($workflow, $user);

            return $this->json([
                'status' => 'approved',
                'workflow_id' => $workflow->getId(),
                'workflow_status' => $workflow->getStatus(),
                'message' => 'Workflow gestartet'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Approval failed', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Approval failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
     /**
     * 3. Listet Workflows
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    #[OA\Get(summary: 'Listet User-Workflows')]
    public function list(Request $request): JsonResponse
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
            'id'          => $w->getId(),
            'session_id'  => $w->getSessionId(),
            // Null‑Check, sonst wirft substr() einen TypeError
            'user_intent' => $w->getUserIntent() 
                ? substr($w->getUserIntent(), 0, 100) 
                : '',
            'status'      => $w->getStatus(),
            // Sicherstellen, dass Steps eine Collection ist
            'steps_count' => $w->getSteps() 
                ? $w->getSteps()->count() 
                : 0,
            // Null‑Check für Datum
            'created_at'  => $w->getCreatedAt() 
                ? $w->getCreatedAt()->format('c') 
                : null,
        ], $workflows);

        return $this->json([
            'workflows' => $result,
            'count'     => count($result)
        ]);
    }
    /**
     * 4. Holt Workflow-Status
     */
    #[Route('/{workflowId}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt Workflow-Details',
        parameters: [new OA\Parameter(name: 'workflowId', in: 'path', required: true)]
    )]
    public function get(int $workflowId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow || $workflow->getUser() !== $user) {
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }
        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $steps[] = [
                'id' => $step->getId(),
                'step_number' => $step->getStepNumber(),
                'type' => $step->getStepType(),
                'description' => $step->getDescription(),
                'status' => $step->getStatus(),
                'requires_confirmation' => $step->requiresConfirmation(),
                'email_details' => $step->getEmailDetails(),
                'result' => $step->getResult(),
                'error' => $step->getErrorMessage()
            ];
        }
        return $this->json([
            'id' => $workflow->getId(),
            'status' => $workflow->getStatus(),
            'user_intent' => $workflow->getUserIntent(),
            'current_step' => $workflow->getCurrentStep(),
            'steps_count' => count($steps),
            'steps' => $steps,
            'created_at' => $workflow->getCreatedAt()->format('c'),
            'completed_at' => $workflow->getCompletedAt()?->format('c')
        ]);
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
                'tool_name' => $step->getToolName() ?? null,
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
   

    // ========================================
    // CONFIRMATIONS
    // ========================================

    /**
     * Bestätigt allgemeinen Step
     */
    #[Route('/step/{stepId}/confirm', name: 'step_confirm', methods: ['POST'])]
    #[OA\Post(
        summary: 'Bestätigt Step',
        parameters: [new OA\Parameter(name: 'stepId', in: 'path', required: true)],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'confirmed', type: 'boolean')]
            )
        )
    )]
    public function confirmStep(int $stepId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step || $step->getWorkflow()->getUser() !== $user) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $workflow = $step->getWorkflow();

        if ($step->getStatus() !== 'pending_confirmation') {
            return $this->json([
                'error' => 'Step wartet nicht auf Confirmation',
                'current_status' => $step->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $confirmed = $data['confirmed'] ?? null;

        if ($confirmed === null) {
            return $this->json(['error' => 'confirmed parameter required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ($confirmed) {
                // Fortsetzen
                $this->executor->confirmAndContinue($workflow, $step, $user);

                return $this->json([
                    'status' => 'confirmed',
                    'workflow_status' => $workflow->getStatus()
                ]);
            } else {
                // Ablehnen
                $step->setStatus('rejected');
                $workflow->setStatus('cancelled');
                $this->em->flush();

                return $this->json([
                    'status' => 'rejected',
                    'workflow_status' => 'cancelled'
                ]);
            }

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Confirmation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sendet bestätigte E-Mail
     */
    #[Route('/step/{stepId}/send-email', name: 'send_email', methods: ['POST'])]
    #[OA\Post(
        summary: 'Sendet bestätigte E-Mail',
        parameters: [new OA\Parameter(name: 'stepId', in: 'path', required: true)]
    )]
    public function sendEmail(int $stepId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step || $step->getWorkflow()->getUser() !== $user) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $workflow = $step->getWorkflow();

        try {
            // Direkt Executor aufrufen
            $this->executor->confirmAndSendEmail($workflow, $step, $user);

            return $this->json([
                'status' => 'sent',
                'workflow_status' => $workflow->getStatus(),
                'message' => 'E-Mail erfolgreich gesendet'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Email sending failed', [
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
     * Lehnt E-Mail ab
     */
    #[Route('/step/{stepId}/reject-email', name: 'reject_email', methods: ['POST'])]
    #[OA\Post(
        summary: 'Lehnt E-Mail ab',
        parameters: [new OA\Parameter(name: 'stepId', in: 'path', required: true)]
    )]
    public function rejectEmail(int $stepId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step || $step->getWorkflow()->getUser() !== $user) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $workflow = $step->getWorkflow();

        $step->setStatus('rejected');
        $step->setErrorMessage('E-Mail vom User abgelehnt');
        
        $workflow->setStatus('cancelled');
        
        $this->em->flush();

        return $this->json([
            'status' => 'rejected',
            'workflow_status' => 'cancelled',
            'message' => 'E-Mail abgelehnt, Workflow abgebrochen'
        ]);
    }

    /**
     * Wählt Job aus
     */
    #[Route('/{workflowId}/select-job', name: 'select_job', methods: ['POST'])]
    #[OA\Post(
        summary: 'Wählt Job aus verfügbaren Jobs',
        parameters: [new OA\Parameter(name: 'workflowId', in: 'path', required: true)],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                required: ['selected_job'],
                properties: [
                    new OA\Property(
                        property: 'selected_job',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'company', type: 'string'),
                            new OA\Property(property: 'url', type: 'string')
                        ]
                    )
                ]
            )
        )
    )]
    public function selectJob(int $workflowId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workflow = $this->workflowRepo->find($workflowId);
        
        if (!$workflow || $workflow->getUser() !== $user) {
            
            return $this->json(['error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
       
        $refnr = $data['selected_job'] ?? null;

        if (!$refnr) {
            return $this->json(['error' => 'No job selected'], Response::HTTP_BAD_REQUEST);
        }
       
        

        $stellenangebote = $workflow->getSteps()[$workflow->getCurrentStep()-2]->getResult()['data']['data']['stellenangebote'];
        
        // array_filter() findet alle passenden Elemente
        $passendeJobs = array_filter(
            $stellenangebote,
            function ($job) use ($refnr) {
                // Prüft, ob der Wert des Schlüssels 'refnr' übereinstimmt
                return $job['refnr'] === $refnr;
            }
        );

        // Da 'refnr' eindeutig sein sollte, nehmen wir das erste Ergebnis
        $stellenbeschreibung = reset($passendeJobs);
       


        // Finde wartenden Decision-Step
        $step = null;
        foreach ($workflow->getSteps() as $s) {
            if ($s->getStatus() === 'pending_confirmation' && $s->getStepType() === 'decision') {
                $step = $s;
                break;
            }
        }

        if (!$step) {
            return $this->json(['error' => 'No job selection pending'], Response::HTTP_BAD_REQUEST);
        }

        try {
           
            // Speichere Auswahl
            $step->setResult([
                'status' => 'selected',
                'refnr' => $refnr,
                'job_title' => $stellenbeschreibung['titel'],
                'company_name' => $stellenbeschreibung['arbeitgeber'],
                'location' => $stellenbeschreibung['arbeitsort']['ort'],
                'job_url' => $stellenbeschreibung['url'] ?? null,
                'full_job_data' => $stellenbeschreibung
            ]);
            $step->setStatus('completed');
            $step->setCompletedAt(new \DateTimeImmutable());
            
            $workflow->setStatus('running');
            $this->em->flush();

            // Setze Workflow fort
            $this->executor->executeWorkflow($workflow, $user);

            return $this->json([
                'status' => 'success',
                'selected_job' => $stellenbeschreibung,
                'workflow_status' => $workflow->getStatus()
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Selection failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // UTILITIES
    // ========================================

    /**
     * Holt E-Mail-Details
     */
    #[Route('/step/{stepId}/email', name: 'step_email_details', methods: ['GET'])]
    #[OA\Get(
        summary: 'Holt vollständige E-Mail-Details',
        parameters: [new OA\Parameter(name: 'stepId', in: 'path', required: true)]
    )]
    public function getEmailDetails(int $stepId): JsonResponse
    {
        $step = $this->em->getRepository(WorkflowStep::class)->find($stepId);

        if (!$step) {
            return $this->json(['error' => 'Step not found'], Response::HTTP_NOT_FOUND);
        }

        $emailDetails = $step->getEmailDetails();

        if (!$emailDetails) {
            return $this->json(['error' => 'No email details'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'step_id' => $stepId,
            'step_number' => $step->getStepNumber(),
            'status' => $step->getStatus(),
            'email_details' => $emailDetails,
            'can_send' => $step->getStatus() === 'pending_confirmation',
            'actions' => [
                'send' => "/api/workflow/step/{$stepId}/send-email",
                'reject' => "/api/workflow/step/{$stepId}/reject-email"
            ]
        ]);
    }

    /**
     * Löscht Workflow
     */
    #[Route('/{workflowId}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Löscht Workflow',
        parameters: [new OA\Parameter(name: 'workflowId', in: 'path', required: true)]
    )]
    public function delete(int $workflowId): JsonResponse
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

            return $this->json([
                'status' => 'deleted',
                'workflow_id' => $workflowId
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Delete failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
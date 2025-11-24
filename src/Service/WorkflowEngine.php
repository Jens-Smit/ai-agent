<?php
// src/Service/WorkflowEngine.php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use App\Service\Workflow\WorkflowPlanner;
use App\Service\Workflow\WorkflowExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Zentrale Workflow-Engine für Multi-Step-Task-Orchestrierung
 * Delegiert Planning an WorkflowPlanner und Execution an WorkflowExecutor
 */
final class WorkflowEngine
{
    public function __construct(
        private WorkflowPlanner $planner,
        private WorkflowExecutor $executor,
        private EntityManagerInterface $em,
        private WorkflowRepository $workflowRepo,
        private AgentStatusService $statusService,
        private LoggerInterface $logger
    ) {}

    /**
     * Erstellt einen neuen Workflow aus User-Intent
     */
    public function createWorkflowFromIntent(string $userIntent, string $sessionId): Workflow
    {
        $this->logger->info('Creating workflow from user intent', [
            'session' => $sessionId,
            'intent' => substr($userIntent, 0, 100)
        ]);

        try {
            // Delegiere Planning an WorkflowPlanner
            $workflow = $this->planner->createWorkflowFromIntent($userIntent, $sessionId);
            
            $this->statusService->addStatus(
                $sessionId,
                sprintf('📋 Workflow erstellt: %d Steps geplant', $workflow->getSteps()->count())
            );

            return $workflow;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create workflow', [
                'session' => $sessionId,
                'error' => $e->getMessage()
            ]);

            $this->statusService->addStatus(
                $sessionId,
                '❌ Fehler beim Erstellen des Workflows: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Führt einen Workflow aus
     */
    public function executeWorkflow(Workflow $workflow): void
    {
        $this->logger->info('Executing workflow', [
            'workflow_id' => $workflow->getId(),
            'session' => $workflow->getSessionId()
        ]);

        try {
            // Delegiere Execution an WorkflowExecutor
            $this->executor->executeWorkflow($workflow);

        } catch (\Exception $e) {
            $this->logger->error('Workflow execution failed', [
                'workflow_id' => $workflow->getId(),
                'error' => $e->getMessage()
            ]);

            $workflow->setStatus('failed');
            $this->em->flush();

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                '❌ Workflow fehlgeschlagen: ' . $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Bestätigt einen wartenden Workflow-Step
     */
    public function confirmStep(Workflow $workflow, bool $confirmed): void
    {
        if ($workflow->getStatus() !== 'waiting_confirmation') {
            throw new \RuntimeException('Workflow is not waiting for confirmation');
        }

        $currentStepNumber = $workflow->getCurrentStep();
        $currentStep = $workflow->getSteps()
            ->filter(fn($s) => $s->getStepNumber() === $currentStepNumber)
            ->first();

        if (!$currentStep) {
            throw new \RuntimeException('Current step not found');
        }

        if ($confirmed) {
            $this->statusService->addStatus(
                $workflow->getSessionId(),
                '✅ Schritt bestätigt, fahre fort...'
            );

            // Delegiere Bestätigung an Executor
            $this->executor->confirmAndContinue($workflow, $currentStep);

        } else {
            $this->statusService->addStatus(
                $workflow->getSessionId(),
                '❌ Schritt abgelehnt, breche Workflow ab'
            );

            $currentStep->setStatus('cancelled');
            $workflow->setStatus('cancelled');
            $this->em->flush();
        }
    }

    /**
     * Gibt Status eines Workflows zurück
     */
    public function getWorkflowStatus(int $workflowId): array
    {
        $workflow = $this->workflowRepo->find($workflowId);

        if (!$workflow) {
            throw new \RuntimeException('Workflow not found');
        }

        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $steps[] = [
                'number' => $step->getStepNumber(),
                'type' => $step->getStepType(),
                'description' => $step->getDescription(),
                'status' => $step->getStatus(),
                'error' => $step->getErrorMessage(),
                'result' => $step->getResult(),
                'email_details' => $step->getEmailDetails(),
                'completed_at' => $step->getCompletedAt()?->format('c')
            ];
        }

        return [
            'id' => $workflow->getId(),
            'status' => $workflow->getStatus(),
            'user_intent' => $workflow->getUserIntent(),
            'current_step' => $workflow->getCurrentStep(),
            'created_at' => $workflow->getCreatedAt()->format('c'),
            'completed_at' => $workflow->getCompletedAt()?->format('c'),
            'steps' => $steps
        ];
    }

    /**
     * Holt aktive Workflows für eine Session
     */
    public function getActiveWorkflowsForSession(string $sessionId): array
    {
        return $this->workflowRepo->findBy(
            ['sessionId' => $sessionId],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Bricht einen laufenden Workflow ab
     */
    public function cancelWorkflow(Workflow $workflow): void
    {
        if (!in_array($workflow->getStatus(), ['created', 'running', 'waiting_confirmation'])) {
            throw new \RuntimeException('Cannot cancel workflow in status: ' . $workflow->getStatus());
        }

        $workflow->setStatus('cancelled');
        
        foreach ($workflow->getSteps() as $step) {
            if ($step->getStatus() === 'pending') {
                $step->setStatus('cancelled');
            }
        }

        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '⛔ Workflow abgebrochen'
        );

        $this->logger->info('Workflow cancelled', [
            'workflow_id' => $workflow->getId()
        ]);
    }
}
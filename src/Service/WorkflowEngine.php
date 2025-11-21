<?php
// src/Service/WorkflowEngine.php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Workflow-Engine für autonome Multi-Step-Task-Ausführung
 * 
 * Ermöglicht dem Personal Assistant:
 * - Komplexe Aufgaben in Steps zu zerlegen
 * - Steps sequenziell oder parallel auszuführen
 * - Auf User-Bestätigung zu warten
 * - State zwischen Steps zu persistieren
 * - Bei Fehlern automatisch zu recovern
 */
final class WorkflowEngine
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
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

        // Agent analysiert Intent und erstellt Workflow-Plan
        $messages = new MessageBag(
            Message::forSystem($this->getWorkflowPlanningPrompt()),
            Message::ofUser($userIntent)
        );

        $result = $this->agent->call($messages);
        $plan = $this->parseWorkflowPlan($result->getContent());

        // Erstelle Workflow-Entity
        $workflow = new Workflow();
        $workflow->setSessionId($sessionId);
        $workflow->setUserIntent($userIntent);
        $workflow->setStatus('created');
        $workflow->setCreatedAt(new \DateTimeImmutable());
        
        // Erstelle Steps
        foreach ($plan['steps'] as $index => $stepData) {
            $step = new WorkflowStep();
            $step->setWorkflow($workflow);
            $step->setStepNumber($index + 1);
            $step->setStepType($stepData['type']);
            $step->setDescription($stepData['description']);
            $step->setToolName($stepData['tool'] ?? null);
            $step->setToolParameters($stepData['parameters'] ?? []);
            $step->setRequiresConfirmation($stepData['requires_confirmation'] ?? false);
            $step->setStatus('pending');
            
            $workflow->addStep($step);
        }

        $this->em->persist($workflow);
        $this->em->flush();

        $this->statusService->addStatus(
            $sessionId,
            sprintf('📋 Workflow erstellt: %d Steps geplant', count($plan['steps']))
        );

        return $workflow;
    }

    /**
     * Führt einen Workflow aus
     */
    public function executeWorkflow(Workflow $workflow): void
    {
        $this->logger->info('Executing workflow', ['workflow_id' => $workflow->getId()]);
        
        $workflow->setStatus('running');
        $this->em->flush();

        $context = []; // Shared context zwischen Steps

        foreach ($workflow->getSteps() as $step) {
            if ($step->getStatus() === 'completed') {
                continue; // Skip already completed steps
            }

            $this->statusService->addStatus(
                $workflow->getSessionId(),
                sprintf('⚙️ Führe Step %d aus: %s', $step->getStepNumber(), $step->getDescription())
            );

            try {
                $result = $this->executeStep($step, $context, $workflow->getSessionId());
                
                // Ergebnis im Context speichern
                $context['step_' . $step->getStepNumber()] = $result;
                
                $step->setResult($result);
                $step->setStatus('completed');
                $step->setCompletedAt(new \DateTimeImmutable());
                
                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('✅ Step %d abgeschlossen', $step->getStepNumber())
                );

                // Wartet auf User-Bestätigung?
                if ($step->requiresConfirmation()) {
                    $workflow->setStatus('waiting_confirmation');
                    $workflow->setCurrentStep($step->getStepNumber());
                    $this->em->flush();
                    
                    $this->statusService->addStatus(
                        $workflow->getSessionId(),
                        sprintf('⏸️ Warte auf Bestätigung für: %s', $step->getDescription())
                    );
                    
                    return; // Pause workflow
                }

            } catch (\Exception $e) {
                $this->logger->error('Workflow step failed', [
                    'workflow_id' => $workflow->getId(),
                    'step' => $step->getStepNumber(),
                    'error' => $e->getMessage()
                ]);

                $step->setStatus('failed');
                $step->setErrorMessage($e->getMessage());
                
                $workflow->setStatus('failed');
                $this->em->flush();
                
                $this->statusService->addStatus(
                    $workflow->getSessionId(),
                    sprintf('❌ Step %d fehlgeschlagen: %s', $step->getStepNumber(), $e->getMessage())
                );
                
                return;
            }

            $this->em->flush();
        }

        // Workflow abgeschlossen
        $workflow->setStatus('completed');
        $workflow->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->statusService->addStatus(
            $workflow->getSessionId(),
            '🎉 Workflow erfolgreich abgeschlossen!'
        );
    }

    /**
     * Führt einen einzelnen Workflow-Step aus
     */
    private function executeStep(WorkflowStep $step, array $context, string $sessionId): array
    {
        $stepType = $step->getStepType();

        return match($stepType) {
            'tool_call' => $this->executeToolCall($step, $context, $sessionId),
            'analysis' => $this->executeAnalysis($step, $context, $sessionId),
            'decision' => $this->executeDecision($step, $context, $sessionId),
            'notification' => $this->executeNotification($step, $context, $sessionId),
            default => throw new \RuntimeException("Unknown step type: {$stepType}")
        };
    }

    /**
     * Führt einen Tool-Call aus
     */
    private function executeToolCall(WorkflowStep $step, array $context, string $sessionId): array
    {
        $toolName = $step->getToolName();
        $parameters = $step->getToolParameters();

        // Ersetze Platzhalter mit Werten aus Context
        $parameters = $this->resolveContextPlaceholders($parameters, $context);

        $this->logger->info('Executing tool call', [
            'tool' => $toolName,
            'parameters' => $parameters
        ]);

        // Agent führt Tool aus
        $prompt = sprintf(
            'Verwende das Tool "%s" mit folgenden Parametern: %s',
            $toolName,
            json_encode($parameters)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        return [
            'tool' => $toolName,
            'result' => $result->getContent()
        ];
    }

    /**
     * Führt eine Analyse aus
     */
    private function executeAnalysis(WorkflowStep $step, array $context, string $sessionId): array
    {
        $description = $step->getDescription();
        
        $prompt = sprintf(
            'Analysiere folgende Daten und %s: %s',
            $description,
            json_encode($context)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        return [
            'analysis' => $result->getContent()
        ];
    }

    /**
     * Führt eine Entscheidung aus
     */
    private function executeDecision(WorkflowStep $step, array $context, string $sessionId): array
    {
        // Beispiel: Entscheide welches Angebot am besten ist
        $description = $step->getDescription();
        
        $prompt = sprintf(
            'Treffe folgende Entscheidung basierend auf den Daten: %s. Daten: %s',
            $description,
            json_encode($context)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        return [
            'decision' => $result->getContent()
        ];
    }

    /**
     * Sendet eine Notification
     */
    private function executeNotification(WorkflowStep $step, array $context, string $sessionId): array
    {
        $message = $step->getDescription();
        $message = $this->resolveContextPlaceholders($message, $context);

        $this->statusService->addStatus($sessionId, '📧 ' . $message);

        return [
            'notification_sent' => true,
            'message' => $message
        ];
    }

    /**
     * Ersetzt Context-Platzhalter in Parametern
     */
    private function resolveContextPlaceholders(mixed $data, array $context): mixed
    {
        if (is_string($data)) {
            // Ersetze {{step_1.result}} mit tatsächlichem Wert
            return preg_replace_callback(
                '/\{\{([^}]+)\}\}/',
                function($matches) use ($context) {
                    $path = explode('.', $matches[1]);
                    $value = $context;
                    
                    foreach ($path as $key) {
                        $value = $value[$key] ?? null;
                        if ($value === null) break;
                    }
                    
                    return $value ?? $matches[0];
                },
                $data
            );
        }

        if (is_array($data)) {
            return array_map(
                fn($item) => $this->resolveContextPlaceholders($item, $context),
                $data
            );
        }

        return $data;
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
            
            $workflow->setStatus('running');
            $this->em->flush();
            
            // Workflow fortsetzen
            $this->executeWorkflow($workflow);
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
     * Parsing des Workflow-Plans vom Agent
     */
    private function parseWorkflowPlan(string $content): array
    {
        // Agent sollte JSON zurückgeben
        // Beispiel:
        // {
        //   "steps": [
        //     {
        //       "type": "tool_call",
        //       "description": "Suche Wohnungen in Berlin Mitte",
        //       "tool": "immobilien_search",
        //       "parameters": {"city": "Berlin", "district": "Mitte", "max_price": 1500},
        //       "requires_confirmation": false
        //     },
        //     {
        //       "type": "decision",
        //       "description": "Wähle beste 5 Angebote aus",
        //       "requires_confirmation": false
        //     },
        //     {
        //       "type": "notification",
        //       "description": "Präsentiere Ergebnisse: {{step_2.decision}}",
        //       "requires_confirmation": true
        //     }
        //   ]
        // }

        // Extrahiere JSON aus Response (kann in Markdown-Code-Block sein)
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{.*"steps".*\}/s', $content, $matches)) {
            $json = $matches[0];
        } else {
            throw new \RuntimeException('Could not parse workflow plan from agent response');
        }

        $plan = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in workflow plan: ' . json_last_error_msg());
        }

        if (!isset($plan['steps']) || !is_array($plan['steps'])) {
            throw new \RuntimeException('Workflow plan missing steps array');
        }

        return $plan;
    }

    /**
     * System-Prompt für Workflow-Planning
     */
    private function getWorkflowPlanningPrompt(): string
    {
        return <<<PROMPT
            Du bist ein Workflow-Planer. Deine Aufgabe ist es, User-Anfragen in ausführbare Workflow-Steps zu zerlegen.

            Analysiere die User-Anfrage und erstelle einen strukturierten Workflow-Plan im JSON-Format.

            VERFÜGBARE STEP-TYPES:
            - tool_call: Ruft ein Tool auf (z.B. Suche, API-Call, Calendar)
            - analysis: Analysiert Daten aus vorherigen Steps
            - decision: Trifft eine Entscheidung basierend auf Daten
            - notification: Sendet eine Nachricht an User

            VERFÜGBARE TOOLS:
            - immobilien_search: Sucht Wohnungen/Immobilien
            - google_calendar_create_event: Erstellt Kalender-Termine
            - web_scraper: Extrahiert Daten von Webseiten
            - api_client: Ruft externe APIs auf
            - mysql_knowledge_search: Sucht in Wissensdatenbank

            OUTPUT-FORMAT (NUR JSON, kein Text davor/danach):
            ```json
            {
            "steps": [
                {
                "type": "tool_call|analysis|decision|notification",
                "description": "Was dieser Step macht",
                "tool": "tool_name (nur bei tool_call)",
                "parameters": {"key": "value"},
                "requires_confirmation": true/false
                }
            ]
            }
            ```

            WICHTIG:
            - Jeder Step sollte atomar und testbar sein
            - Verwende {{step_N.result}} um auf Ergebnisse vorheriger Steps zu referenzieren
            - Setze requires_confirmation=true wenn User-Bestätigung nötig ist
            - Wenn ein benötigtes Tool nicht existiert, füge einen Step hinzu der das Tool beim DevAgent anfordert

            BEISPIEL:
            User: "Such mir eine Wohnung in Berlin Mitte für 1500€"

            ```json
            {
            "steps": [
                {
                "type": "tool_call",
                "description": "Suche Wohnungen in Berlin Mitte bis 1500€",
                "tool": "immobilien_search",
                "parameters": {
                    "city": "Berlin",
                    "district": "Mitte",
                    "max_price": 1500,
                    "min_rooms": 3,
                    "max_rooms": 4,
                    "radius_km": 2
                },
                "requires_confirmation": false
                },
                {
                "type": "analysis",
                "description": "Analysiere Suchergebnisse und filtere beste Angebote",
                "requires_confirmation": false
                },
                {
                "type": "notification",
                "description": "Präsentiere Top-5 Angebote: {{step_2.analysis}}",
                "requires_confirmation": true
                },
                {
                "type": "tool_call",
                "description": "Vereinbare Besichtigungstermine für bestätigte Angebote",
                "tool": "google_calendar_create_event",
                "parameters": {
                    "title": "Wohnungsbesichtigung {{step_1.address}}",
                    "description": "Besichtigung der Wohnung"
                },
                "requires_confirmation": false
                }
            ]
            }
            ```
            PROMPT;
    }
}
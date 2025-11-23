<?php
// src/Service/WorkflowEngine.php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\UserDocumentRepository;
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
        private UserDocumentRepository $documentRepo,
        private AgentStatusService $statusService,
        private LoggerInterface $logger,
        private string $projectRootDir
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
     * Führt einen Tool-Call aus mit verbesserter Platzhalter-Auflösung
     */
    private function executeToolCall(WorkflowStep $step, array $context, string $sessionId): array
    {
        $toolName = $step->getToolName();
        $parameters = $step->getToolParameters();

        // Ersetze Platzhalter mit Werten aus Context
        $parameters = $this->resolveContextPlaceholders($parameters, $context);

        $this->logger->info('Executing tool call', [
            'tool' => $toolName,
            'parameters' => $parameters,
            'context_keys' => array_keys($context)
        ]);

        // SPEZIALBEHANDLUNG: SendMailTool
        if ($toolName === 'send_email' || $toolName === 'SendMailTool') {
            return $this->prepareSendMailDetails($step, $parameters, $sessionId, $context);
        }

        // Normale Tool-Ausführung
        $prompt = sprintf(
            'Verwende das Tool "%s" mit folgenden Parametern: %s',
            $toolName,
            json_encode($parameters, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        return [
            'tool' => $toolName,
            'result' => $result->getContent()
        ];
    }   

    /**
     * Bereitet E-Mail-Details für Vorschau und Bestätigung vor (VERBESSERT)
     */
    private function prepareSendMailDetails(WorkflowStep $step, array $parameters, string $sessionId, array $context): array{
        $userId = $GLOBALS['current_user_id'] ?? null;
        if (!$userId) {
            throw new \RuntimeException('User context not available');
        }

        // Resolve Platzhalter in allen Parametern
        $resolvedParams = $this->resolveContextPlaceholders($parameters, $context);

        // Extrahiere E-Mail-Details
        $recipient = $resolvedParams['to'] ?? 'Unbekannt';
        $subject = $resolvedParams['subject'] ?? 'Kein Betreff';
        $body = $resolvedParams['body'] ?? '';
        $attachmentIds = $resolvedParams['attachments'] ?? [];

        // Normalisiere Attachment-IDs (falls als String oder einzelner Wert)
        if (!is_array($attachmentIds)) {
            $attachmentIds = [$attachmentIds];
        }

        // Lade Anhang-Details
        $attachmentDetails = [];
        foreach ($attachmentIds as $docId) {
            // Handle sowohl numerische IDs als auch string IDs
            if (empty($docId)) continue;
            
            $document = $this->documentRepo->find($docId);
            if ($document && $document->getUser()->getId() === $userId) {
                $attachmentDetails[] = [
                    'id' => $document->getId(),
                    'filename' => $document->getOriginalFilename(),
                    'size' => $document->getFileSize(),
                    'size_human' => $this->formatBytes($document->getFileSize()),
                    'mime_type' => $document->getMimeType(),
                    'download_url' => '/api/documents/' . $document->getId() . '/download'
                ];
            }
        }

        // Speichere detaillierte E-Mail-Informationen im Step
        $emailDetails = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'body_preview' => mb_substr(strip_tags($body), 0, 200) . (mb_strlen($body) > 200 ? '...' : ''),
            'body_length' => mb_strlen($body),
            'attachments' => $attachmentDetails,
            'attachment_count' => count($attachmentDetails),
            'total_attachment_size' => array_sum(array_column($attachmentDetails, 'size')),
            'prepared_at' => (new \DateTimeImmutable())->format('c'),
            'ready_to_send' => true,
            // Speichere auch die originalen Parameter für späteres Versenden
            '_original_params' => $resolvedParams
        ];

        $step->setEmailDetails($emailDetails);
        $this->em->flush();

        $this->statusService->addStatus(
            $sessionId,
            sprintf(
                '📧 E-Mail vorbereitet an %s - Betreff: "%s" (%d Anhänge, %s)',
                $recipient,
                mb_substr($subject, 0, 50),
                count($attachmentDetails),
                $this->formatBytes($emailDetails['total_attachment_size'])
            )
        );

        return [
            'tool' => 'send_email',
            'status' => 'prepared',
            'email_details' => $emailDetails,
            'message' => 'E-Mail vorbereitet und wartet auf Bestätigung'
        ];
    }


    /**
     * Führt das tatsächliche Versenden der E-Mail aus (nach Bestätigung)
     */
    private function executeSendEmail(WorkflowStep $step, string $sessionId): array
    {
        $emailDetails = $step->getEmailDetails();
        if (!$emailDetails || !($emailDetails['ready_to_send'] ?? false)) {
            throw new \RuntimeException('E-Mail nicht zum Versenden bereit');
        }

        $userId = $GLOBALS['current_user_id'] ?? null;
        if (!$userId) {
            throw new \RuntimeException('User context not available');
        }

        // Rufe SendMailTool direkt auf
        $prompt = sprintf(
            'Sende die vorbereitete E-Mail mit folgenden Details: %s',
            json_encode([
                'to' => $emailDetails['recipient'],
                'subject' => $emailDetails['subject'],
                'body' => $emailDetails['body'],
                'attachments' => array_column($emailDetails['attachments'], 'id')
            ])
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->agent->call($messages);

        $this->statusService->addStatus(
            $sessionId,
            sprintf('✅ E-Mail erfolgreich versendet an %s', $emailDetails['recipient'])
        );

        return [
            'tool' => 'send_email',
            'status' => 'sent',
            'recipient' => $emailDetails['recipient'],
            'sent_at' => (new \DateTimeImmutable())->format('c'),
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
     * Führt eine Notification aus (VERBESSERT)
     */
    private function executeNotification(WorkflowStep $step, array $context, string $sessionId): array
    {
        $message = $step->getDescription();
        
        // Resolve Platzhalter im Message-Text
        $resolvedMessage = $this->resolveContextPlaceholders($message, $context);
        
        // Prüfe ob noch Platzhalter übrig sind
        if (preg_match('/\{\{[^}]+\}\}/', $resolvedMessage)) {
            $this->logger->warning('Unresolved placeholders in notification', [
                'message' => $resolvedMessage,
                'context_keys' => array_keys($context)
            ]);
        }
        
        $this->statusService->addStatus($sessionId, '📧 ' . $resolvedMessage);

        return [
            'notification_sent' => true,
            'message' => $resolvedMessage
        ];
    }

    /**
 * Ersetzt Context-Platzhalter in Parametern (VERBESSERT)
 */
private function resolveContextPlaceholders(mixed $data, array $context): mixed
{
    if (is_string($data)) {
        // Ersetze {{step_1.result}} mit tatsächlichem Wert
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function($matches) use ($context) {
                $path = $matches[1];
                
                // Parse den Pfad: step_1.result.documents[0].identifier
                $parts = preg_split('/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
                
                $value = $context;
                
                foreach ($parts as $key) {
                    if (is_array($value)) {
                        // Numerischer Index
                        if (is_numeric($key)) {
                            $value = $value[(int)$key] ?? null;
                        } else {
                            $value = $value[$key] ?? null;
                        }
                    } elseif (is_object($value)) {
                        $value = $value->$key ?? null;
                    } else {
                        $value = null;
                        break;
                    }
                    
                    if ($value === null) {
                        $this->logger->warning('Placeholder not found', [
                            'placeholder' => $matches[0],
                            'path' => $path,
                            'failed_at' => $key
                        ]);
                        break;
                    }
                }
                
                // Wenn Wert ein Array oder Objekt ist, konvertiere zu JSON
                if (is_array($value) || is_object($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
                
                return $value ?? $matches[0]; // Original beibehalten wenn nicht gefunden
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
     * Bestätigt einen wartenden Workflow-Step (angepasst für E-Mails)
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

            // Spezialbehandlung: Wenn es ein vorbereiteter E-Mail-Step ist, sende jetzt
            if ($currentStep->getToolName() === 'send_email' && $currentStep->getEmailDetails()) {
                try {
                    $result = $this->executeSendEmail($currentStep, $workflow->getSessionId());
                    $currentStep->setResult($result);
                    $currentStep->setStatus('completed');
                    $currentStep->setCompletedAt(new \DateTimeImmutable());
                } catch (\Exception $e) {
                    $this->logger->error('Failed to send email after confirmation', [
                        'error' => $e->getMessage()
                    ]);
                    $currentStep->setStatus('failed');
                    $currentStep->setErrorMessage($e->getMessage());
                    $workflow->setStatus('failed');
                    $this->em->flush();
                    return;
                }
            } else {
                $currentStep->setStatus('completed');
                $currentStep->setCompletedAt(new \DateTimeImmutable());
            }
            
            $workflow->setStatus('running');
            $workflow->setCurrentStep(null);
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
     * Formatiert Bytes in lesbare Größe
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
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
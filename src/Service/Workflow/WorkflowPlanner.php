<?php
// src/Service/Workflow/WorkflowPlanner.php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WorkflowPlanner
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    public function createWorkflowFromIntent(string $userIntent, string $sessionId): Workflow
    {
        $messages = new MessageBag(
            Message::forSystem($this->getWorkflowPlanningPrompt()),
            Message::ofUser($userIntent)
        );

        $result = $this->agent->call($messages);
        $plan = $this->parseWorkflowPlan($result->getContent());

        // 🔧 FIX: Validiere und optimiere Plan mit besserer Fehlerbehandlung
        $plan = $this->validateAndOptimizePlan($plan);

        $workflow = new Workflow();
        $workflow->setSessionId($sessionId);
        $workflow->setUserIntent($userIntent);
        $workflow->setStatus('created');
        $workflow->setCreatedAt(new \DateTimeImmutable());

        foreach ($plan['steps'] as $index => $stepData) {
            $step = $this->createStep($workflow, $index + 1, $stepData);
            $workflow->addStep($step);
        }

        $this->em->persist($workflow);
        $this->em->flush();

        $this->logger->info('Workflow created', [
            'workflow_id' => $workflow->getId(),
            'steps_count' => count($plan['steps']),
            'session' => $sessionId
        ]);

        return $workflow;
    }

    private function createStep(Workflow $workflow, int $stepNumber, array $stepData): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->setWorkflow($workflow);
        $step->setStepNumber($stepNumber);
        $step->setStepType($stepData['type']);
        $step->setDescription($stepData['description']);
        $step->setToolName($stepData['tool'] ?? null);
        $step->setToolParameters($stepData['parameters'] ?? []);
        $step->setRequiresConfirmation($stepData['requires_confirmation'] ?? false);
        $step->setStatus('pending');

        if (isset($stepData['output_format'])) {
            $step->setExpectedOutputFormat($stepData['output_format']);
        }

        return $step;
    }

    private function validateAndOptimizePlan(array $plan): array
    {
        if (!isset($plan['steps']) || !is_array($plan['steps']) || empty($plan['steps'])) {
            throw new \RuntimeException('Invalid workflow plan: missing or empty steps array');
        }

        foreach ($plan['steps'] as $index => &$step) {
            if (!isset($step['type']) || !in_array($step['type'], ['tool_call', 'analysis', 'decision', 'notification'])) {
                throw new \RuntimeException("Invalid step type at index {$index}");
            }

            $step['description'] = $step['description'] ?? 'Keine Beschreibung';
            $step['parameters'] = $step['parameters'] ?? [];
            $step['requires_confirmation'] = $step['requires_confirmation'] ?? false;

            // 🔧 FIX: Für Analysis/Decision Steps IMMER Output-Format erzwingen
            if (in_array($step['type'], ['analysis', 'decision'])) {
                if (!isset($step['output_format'])) {
                    $step['output_format'] = $this->inferOutputFormat($step, $plan['steps'], $index);
                }

                // 🔧 FIX: Stelle sicher, dass output_format richtig strukturiert ist
                if (isset($step['output_format']) && !isset($step['output_format']['fields'])) {
                    // Falls output_format direkt die Felder enthält
                    $step['output_format'] = [
                        'type' => 'object',
                        'fields' => $step['output_format']
                    ];
                }
            }

            if ($step['type'] === 'tool_call' && empty($step['tool'])) {
                throw new \RuntimeException("Missing tool name for tool_call at step {$index}");
            }
        }

        return $plan;
    }

    private function inferOutputFormat(array $currentStep, array $allSteps, int $currentIndex): ?array
    {
        $requiredFields = [];

        // Suche in nachfolgenden Steps nach Referenzen
        for ($i = $currentIndex + 1; $i < count($allSteps); $i++) {
            $nextStep = $allSteps[$i];
            $params = json_encode($nextStep['parameters'] ?? []);

            $stepRef = 'step_' . ($currentIndex + 1);
            if (preg_match_all('/\{\{' . preg_quote($stepRef) . '\.result\.(\w+)\}\}/', $params, $matches)) {
                foreach ($matches[1] as $field) {
                    $requiredFields[$field] = 'string';
                }
            }
        }

        // 🔧 FIX: Auch aus description Felder extrahieren
        $description = $currentStep['description'] ?? '';
        if (preg_match_all('/(\w+):\s*"([^"]+)"/', $description, $matches)) {
            foreach ($matches[1] as $field) {
                if (!in_array($field, ['type', 'description', 'tool'])) {
                    $requiredFields[$field] = 'string';
                }
            }
        }

        if (empty($requiredFields)) {
            return null;
        }

        return [
            'type' => 'object',
            'fields' => $requiredFields
        ];
    }

    private function parseWorkflowPlan(string $content): array
    {
        // Strategie 1: JSON in Code-Blöcken
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        }
        // Strategie 2: JSON ohne Code-Block
        elseif (preg_match('/\{[^{]*"steps"[^}]*\[.*?\]\s*\}/s', $content, $matches)) {
            $json = $matches[0];
        }
        // Strategie 3: Extrahiere größtes JSON-Objekt
        else {
            preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $content, $matches);
            $json = null;
            
            foreach ($matches[0] as $candidate) {
                $decoded = json_decode($candidate, true);
                if ($decoded && isset($decoded['steps'])) {
                    $json = $candidate;
                    break;
                }
            }

            if (!$json) {
                $this->logger->error('Could not parse workflow plan', [
                    'content_preview' => substr($content, 0, 500)
                ]);
                throw new \RuntimeException('Could not parse workflow plan from agent response');
            }
        }

        // Bereinige JSON
        $json = preg_replace('#//.*#', '', $json);
        $json = preg_replace('/,\s*([\]}])/', '$1', $json);

        $plan = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in workflow plan: ' . json_last_error_msg());
        }

        return $plan;
    }

    private function getWorkflowPlanningPrompt(): string
    {
        return <<<PROMPT
Du bist ein Workflow-Planer. Erstelle IMMER strukturierte, ausführbare Workflows.

KRITISCH WICHTIG:
1. JEDER analysis/decision Step MUSS ein "output_format" definieren
2. Das output_format enthält die EXAKTEN Feldnamen, die nachfolgende Steps benötigen
3. Verwende {{step_N.result.FELDNAME}} um auf Felder zuzugreifen

STEP-TYPES:
- tool_call: Ruft ein Tool auf
- analysis: Analysiert Daten → MUSS output_format haben
- decision: Trifft Entscheidung → MUSS output_format haben
- notification: Sendet Nachricht

VERFÜGBARE TOOLS:
- job_search: Sucht Jobs (Parameter: what, where, size)
- company_career_contact_finder: Findet Karriere-Kontakte (Parameter: company_name)
- user_document_search: Sucht Dokumente (Parameter: searchTerm, category)
- user_document_read: Liest Dokument (Parameter: identifier)
- user_document_list: Listet Dokumente (Parameter: category)
- PdfGenerator: Erstellt PDFs (Parameter: text, filename)
- send_email: Versendet E-Mail (Parameter: to, subject, body, attachments)
- google_search: Google-Suche (Parameter: query)
- web_scraper: Scraped Webseite (Parameter: url)

OUTPUT-FORMAT (NUR JSON):
```json
{
  "steps": [
    {
      "type": "tool_call",
      "description": "Suche Jobs in Hamburg",
      "tool": "job_search",
      "parameters": {
        "what": "Entwickler",
        "where": "Hamburg",
        "size": 1
      }
    },
    {
      "type": "analysis",
      "description": "Extrahiere Jobdetails",
      "output_format": {
        "job_title": "string",
        "company_name": "string",
        "job_description": "string",
        "job_location": "string"
      }
    },
    {
      "type": "tool_call",
      "description": "Finde Firmenkontakte",
      "tool": "company_career_contact_finder",
      "parameters": {
        "company_name": "{{step_2.result.company_name}}"
      }
    }
  ]
}
```

REGELN:
1. Output-Format IMMER definieren bei analysis/decision
2. Feldnamen müssen EXAKT in Platzhaltern verwendet werden
3. requires_confirmation: true für E-Mails, Termine, Zahlungen
4. Kleine, atomare Steps bevorzugen
PROMPT;
    }
}
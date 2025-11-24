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

/**
 * Workflow-Planer: Erstellt strukturierte Workflows aus User-Intents
 * Mit verbesserter JSON-Extraktion und Validierung
 */
final class WorkflowPlanner
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Erstellt Workflow aus User-Intent
     */
    public function createWorkflowFromIntent(string $userIntent, string $sessionId): Workflow
    {
        // Agent analysiert Intent und erstellt Workflow-Plan
        $messages = new MessageBag(
            Message::forSystem($this->getWorkflowPlanningPrompt()),
            Message::ofUser($userIntent)
        );

        $result = $this->agent->call($messages);
        $plan = $this->parseWorkflowPlan($result->getContent());

        // Validiere und optimiere Plan
        $plan = $this->validateAndOptimizePlan($plan);

        // Erstelle Workflow-Entity
        $workflow = new Workflow();
        $workflow->setSessionId($sessionId);
        $workflow->setUserIntent($userIntent);
        $workflow->setStatus('created');
        $workflow->setCreatedAt(new \DateTimeImmutable());

        // Erstelle Steps
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

    /**
     * Erstellt einzelnen Workflow-Step
     */
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

        // Speichere erwartete Output-Struktur für Analysis/Decision Steps
        if (isset($stepData['output_format'])) {
            $step->setExpectedOutputFormat($stepData['output_format']);
        }

        return $step;
    }

    /**
     * Validiert und optimiert Workflow-Plan
     */
    private function validateAndOptimizePlan(array $plan): array
    {
        if (!isset($plan['steps']) || !is_array($plan['steps']) || empty($plan['steps'])) {
            throw new \RuntimeException('Invalid workflow plan: missing or empty steps array');
        }

        foreach ($plan['steps'] as $index => &$step) {
            // Validiere Step-Type
            if (!isset($step['type']) || !in_array($step['type'], ['tool_call', 'analysis', 'decision', 'notification'])) {
                throw new \RuntimeException("Invalid step type at index {$index}");
            }

            // Setze Defaults
            $step['description'] = $step['description'] ?? 'Kein Beschreibung';
            $step['parameters'] = $step['parameters'] ?? [];
            $step['requires_confirmation'] = $step['requires_confirmation'] ?? false;

            // Für Analysis/Decision Steps: Füge strukturierte Output-Format hinzu
            if (in_array($step['type'], ['analysis', 'decision'])) {
                if (!isset($step['output_format'])) {
                    $step['output_format'] = $this->inferOutputFormat($step, $plan['steps'], $index);
                }
            }

            // Validiere Tool-Name bei tool_call
            if ($step['type'] === 'tool_call' && empty($step['tool'])) {
                throw new \RuntimeException("Missing tool name for tool_call at step {$index}");
            }
        }

        return $plan;
    }

    /**
     * Leitet erwartetes Output-Format ab aus nachfolgenden Steps
     */
    private function inferOutputFormat(array $currentStep, array $allSteps, int $currentIndex): ?array
    {
        // Suche nach Platzhaltern in nachfolgenden Steps
        $requiredFields = [];

        for ($i = $currentIndex + 1; $i < count($allSteps); $i++) {
            $nextStep = $allSteps[$i];
            $params = json_encode($nextStep['parameters'] ?? []);

            // Finde alle Platzhalter die auf diesen Step verweisen
            $stepRef = 'step_' . ($currentIndex + 1);
            if (preg_match_all('/\{\{' . preg_quote($stepRef) . '\.result\.(\w+)\}\}/', $params, $matches)) {
                foreach ($matches[1] as $field) {
                    $requiredFields[$field] = 'string'; // Default type
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

    /**
     * Parsing des Workflow-Plans vom Agent mit verbesserter Fehlertoleranz
     */
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

        // Bereinige JSON (entferne Kommentare, trailing commas)
        $json = preg_replace('#//.*#', '', $json); // Entferne Kommentare
        $json = preg_replace('/,\s*([\]}])/', '$1', $json); // Entferne trailing commas

        $plan = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in workflow plan: ' . json_last_error_msg());
        }

        return $plan;
    }

    /**
     * System-Prompt für Workflow-Planning mit strukturierter Output-Vorgabe
     */
    private function getWorkflowPlanningPrompt(): string
    {
        return <<<PROMPT
Du bist ein Workflow-Planer. Deine Aufgabe ist es, User-Anfragen in ausführbare Workflow-Steps zu zerlegen.

KRITISCH WICHTIG FÜR ANALYSIS/DECISION STEPS:
Wenn ein Step vom Typ "analysis" oder "decision" ist und nachfolgende Steps auf dessen Ergebnis zugreifen, 
MUSST du ein "output_format" definieren, das die erwarteten Felder spezifiziert.

VERFÜGBARE STEP-TYPES:
- tool_call: Ruft ein Tool auf (z.B. Suche, API-Call, Calendar)
- analysis: Analysiert Daten - MUSS strukturierte JSON-Ausgabe liefern wenn nachfolgende Steps darauf zugreifen
- decision: Trifft eine Entscheidung - MUSS strukturierte JSON-Ausgabe liefern wenn nachfolgende Steps darauf zugreifen
- notification: Sendet eine Nachricht an User

VERFÜGBARE TOOLS:
- job_search: Sucht Jobs/Stellenanzeigen
- PdfGenerator: Generiert PDF-Dokumente
- company_career_contact_finder: Findet Karriere-Kontaktdaten von Firmen
- google_calendar_create_event: Erstellt Kalender-Termine
- send_email: Versendet E-Mails (erfordert Bestätigung)
- web_scraper: Extrahiert Daten von Webseiten
- api_client: Ruft externe APIs auf
- mysql_knowledge_search: Sucht in Wissensdatenbank
- user_document_read: Liest und extrahiert Text aus User-Dokumenten
- user_document_search: Sucht nach Dokumenten anhand von Suchbegriff. Durchsucht Dokumentname, Beschreibung und Tags. Optional kann zusätzlich nach Kategorie gefiltert werden.
- user_document_metadata: Liest Metadaten eines User-Dokuments (Name, Größe, Typ, Upload-Datum)
- user_document_list: Listet alle User-Dokumente auf, optional gefiltert nach Kategorie
- google_search: Führt eine Google-Suche durch und liefert URLs und Snippets
- information_extractor: Extrahiert Informationen von Webseiten basierend auf einer Suchanfrage
OUTPUT-FORMAT (NUR JSON, kein Text davor/danach):
```json
{
  "steps": [
    {
      "type": "tool_call",
      "description": "Suche nach Backend-Entwickler Jobs in Hamburg",
      "tool": "job_search",
      "parameters": {
        "query": "Backend Entwickler Hamburg",
        "limit": 5
      },
      "requires_confirmation": false
    },
    {
      "type": "analysis",
      "description": "Extrahiere Firmenname aus Suchergebnissen",
      "output_format": {
        "company_name": "string",
        "job_title": "string",
        "location": "string"
      }
    },
    {
      "type": "tool_call",
      "description": "Finde Kontaktdaten der Firma",
      "tool": "company_career_contact_finder",
      "parameters": {
        "company_name": "{{step_2.result.company_name}}"
      }
    }
  ]
}
```

WICHTIGE REGELN:
1. Jeder Step sollte atomar und testbar sein
2. Verwende {{step_N.result.FIELD}} um auf strukturierte Felder vorheriger Steps zuzugreifen
3. Bei analysis/decision Steps: Definiere IMMER "output_format" wenn nachfolgende Steps darauf zugreifen
4. Setze requires_confirmation=true für kritische Aktionen (E-Mail senden, Zahlungen, Termine buchen)
5. Halte Steps klein und fokussiert - lieber mehr kleine Steps als wenige große

BEISPIEL FÜR STRUKTURIERTE ANALYSIS:
Falsch ❌:
{
  "type": "analysis",
  "description": "Analysiere Job-Details"
}

Richtig ✅:
{
  "type": "analysis",
  "description": "Analysiere Job-Details",
  "output_format": {
    "company_name": "string",
    "contact_email": "string",
    "application_deadline": "string"
  }
}
PROMPT;
    }
}
<?php
// src/Service/Workflow/WorkflowPlanner.php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WorkflowPlanner
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private Security $security
    ) {}

    public function createWorkflowFromIntent(string $userIntent, string $sessionId): Workflow
    {
        /** @var User $user */
        $user = $this->security->getUser();
        $messages = new MessageBag(
            Message::forSystem($this->getWorkflowPlanningPrompt()),
            Message::ofUser($userIntent)
        );

        $result = $this->agent->call($messages);
        $plan = $this->parseWorkflowPlan($result->getContent());

        // 🔧 FIX: Validiere und optimiere Plan
        $plan = $this->validateAndOptimizePlan($plan);

        $workflow = new Workflow();
        $workflow->setSessionId($sessionId);
        $workflow->setUserIntent($userIntent);
        $workflow->setStatus('created');
        $workflow->setUser($user);

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

            // 🔧 FIX: Für Analysis/Decision Steps output_format erzwingen
            if (in_array($step['type'], ['analysis', 'decision'])) {
                if (!isset($step['output_format'])) {
                    $step['output_format'] = $this->inferOutputFormat($step, $plan['steps'], $index);
                }

                if (isset($step['output_format']) && !isset($step['output_format']['fields'])) {
                    $step['output_format'] = [
                        'type' => 'object',
                        'fields' => $step['output_format']
                    ];
                }
            }

            if ($step['type'] === 'tool_call' && empty($step['tool'])) {
                throw new \RuntimeException("Missing tool name for tool_call at step {$index}");
            }

            // ✅ NEU: Automatische Validierung und Korrektur von send_email attachments
            if ($step['type'] === 'tool_call' && $step['tool'] === 'send_email') {
                $step['parameters'] = $this->normalizeEmailAttachments($step['parameters']);
            }
        }

        return $plan;
    }

    /**
     * ✅ NEU: Normalisiert Attachments in send_email Parameters
     */
    private function normalizeEmailAttachments(array $params): array
    {
        if (!isset($params['attachments'])) {
            return $params;
        }

        $attachments = $params['attachments'];

        // Falls attachments ein String ist (JSON oder einzelner Wert)
        if (is_string($attachments)) {
            // Versuche JSON zu parsen
            $decoded = json_decode($attachments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $attachments = $decoded;
            } else {
                // Einzelner Wert - behandle als document_id Platzhalter
                $attachments = [$attachments];
            }
        }

        // Normalisiere Array-Elemente
        if (is_array($attachments)) {
            $normalized = [];
            foreach ($attachments as $attachment) {
                if (is_string($attachment)) {
                    // String-Platzhalter → behandle als document_id
                    $normalized[] = [
                        'type' => 'document_id',
                        'value' => $attachment
                    ];
                } elseif (is_array($attachment)) {
                    // Bereits strukturiert - prüfe ob type gesetzt ist
                    if (!isset($attachment['type'])) {
                        $attachment['type'] = 'document_id';
                    }
                    $normalized[] = $attachment;
                }
            }
            $attachments = $normalized;
        }

        // Konvertiere zurück zu JSON für Tool-Parameter
        $params['attachments'] = json_encode($attachments, JSON_UNESCAPED_UNICODE);

        return $params;
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

        // Extrahiere auch aus description
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
        $json = null;

        // Strategie 1: JSON in Code-Blöcken
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        }
        // Strategie 2: JSON-Objekt mit "steps"
        elseif (preg_match('/\{.*?\"steps\"\s*:\s*\[.*?\]\s*\}/s', $content, $matches)) {
            $json = $matches[0];
        }
        // Strategie 3: Erstes JSON-Objekt
        else {
            if (preg_match('/^.*?(\{.*\}).*?$/s', $content, $matches)) {
                $json = $matches[1];
            }
        }

        if (!$json) {
            $this->logger->error('Could not parse workflow plan: No JSON structure found.', [
                'content_preview' => substr($content, 0, 500)
            ]);
            throw new \RuntimeException('Could not parse workflow plan from agent response: No JSON found.');
        }

        // JSON-Bereinigung
        $json = preg_replace('/\/\*(.*?)\*\//s', '', $json); // Block-Kommentare
        $json = preg_replace('/\/\/.*$/m', '', $json);      // Zeilen-Kommentare
        $json = preg_replace('/,\s*([\]\}])/', '$1', $json); // Trailing commas
        
        $json = trim($json);
        if (!str_starts_with($json, '{') && !str_starts_with($json, '[')) {
            $json = '{' . $json . '}';
        }

        $plan = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON after cleanup.', [
                'json_error' => json_last_error_msg(),
                'json_content' => $json
            ]);
            throw new \RuntimeException('Invalid JSON in workflow plan: ' . json_last_error_msg());
        }
        
        if (!isset($plan['steps']) || !is_array($plan['steps'])) {
            $this->logger->error('Parsed JSON is missing the required "steps" key.', [
                'json_content' => $json
            ]);
            throw new \RuntimeException('Parsed JSON is not a valid workflow plan (missing "steps" array).');
        }

        return $plan;
    }

    private function getWorkflowPlanningPrompt(): string
    {
        return <<<PROMPT
Du bist ein intelligenter Workflow-Planer. Erstelle effiziente, ausführbare Workflows basierend auf der User-Anfrage.

🎯 KRITISCHE REGELN:

1. **Output-Format**: JEDER analysis/decision Step MUSS "output_format" definieren
2. **Platzhalter**: Verwende {{step_N.result.FELDNAME}} für Referenzen
3. **Flexibilität**: Wähle die EINFACHSTE Lösung - nicht immer PDFs nötig!
4. **Bestätigung**: requires_confirmation: true für E-Mails, Termine, Zahlungen

📋 VERFÜGBARE TOOLS:

**Job-Suche:**
- job_search: Sucht Stellenangebote (Parameter: what, where, size)
- company_career_contact_finder: Findet Karriere-Kontakte (Parameter: company_name)

**Dokumente:**
- user_document_search: Sucht Dokumente (Parameter: searchTerm, category)
- user_document_read: Liest Dokument (Parameter: identifier)
- user_document_list: Listet Dokumente (Parameter: category)

**Generierung:**
- PdfGenerator: Erstellt PDFs (Parameter: text, filename) → Gibt zurück: {document_id, filepath, filename}

**Kommunikation:**
- send_email: Versendet E-Mail (Parameter: to, subject, body, attachments?)
  - body: Kann langen Text enthalten - KEIN PDF nötig wenn Text direkt im Body steht!
  - attachments: Optional! Format: [{"type":"document_id","value":"123"}] oder ["{{step_N.result.document_id}}"]

**Web:**
- google_search: Google-Suche (Parameter: query)
- web_scraper: Scraped Webseite (Parameter: url)

🎨 BEWERBUNGS-WORKFLOWS - ZWEI VARIANTEN:

**Variante A: Text direkt in E-Mail (EINFACHER, BEVORZUGT)**
```json
{
  "steps": [
    {"type": "tool_call", "tool": "job_search", "parameters": {"what": "Entwickler", "where": "Hamburg", "size": 1}},
    {"type": "analysis", "description": "Extrahiere Job-Details", 
     "output_format": {"job_title": "string", "company_name": "string", "job_url": "string"}},
    {"type": "tool_call", "tool": "company_career_contact_finder", 
     "parameters": {"company_name": "{{step_2.result.company_name}}"}},
    {"type": "tool_call", "tool": "user_document_search", 
     "parameters": {"searchTerm": "Lebenslauf", "category": "resume"}},
    {"type": "analysis", "description": "Extrahiere Lebenslauf-ID",
     "output_format": {"resume_id": "string"}},
    {"type": "analysis", "description": "Erstelle Bewerbungstext",
     "output_format": {"cover_letter_text": "string"}},
    {"type": "tool_call", "tool": "send_email", "requires_confirmation": true,
     "parameters": {
       "to": "{{step_3.result.application_email|step_3.result.general_email}}",
       "subject": "Bewerbung als {{step_2.result.job_title}}",
       "body": "{{step_6.result.cover_letter_text}}",
       "attachments": ["{{step_5.result.resume_id}}"]
     }}
  ]
}
```

**Variante B: Mit PDF-Anschreiben (NUR wenn explizit gewünscht)**
```json
{
  "steps": [
    // ... Steps 1-6 wie oben ...
    {"type": "tool_call", "tool": "PdfGenerator",
     "parameters": {
       "text": "{{step_6.result.cover_letter_text}}",
       "filename": "Bewerbung_{{step_2.result.company_name}}.pdf"
     }},
    {"type": "tool_call", "tool": "send_email", "requires_confirmation": true,
     "parameters": {
       "to": "{{step_3.result.application_email|step_3.result.general_email}}",
       "subject": "Bewerbung als {{step_2.result.job_title}}",
       "body": "Sehr geehrte Damen und Herren,\n\nanbei sende ich Ihnen meine Bewerbungsunterlagen.\n\nMit freundlichen Grüßen",
       "attachments": ["{{step_7.result.document_id}}", "{{step_5.result.resume_id}}"]
     }}
  ]
}
```

⚡ OPTIMIERUNGSREGELN:

1. **Bevorzuge Variante A** (Text direkt im Body) - einfacher und schneller
2. **Verwende Variante B** nur wenn User explizit "PDF" oder "Anschreiben als Anhang" sagt
3. **Attachments sind OPTIONAL** - weglassen wenn nicht nötig
4. **Kleine Steps** - besser 7 kleine als 3 große Steps
5. **Pipe-Fallbacks** für E-Mails: {{email1|email2|default@example.com}}

📤 OUTPUT FORMAT (NUR JSON, KEINE ERKLÄRUNG):
```json
{
  "steps": [ /* Deine Steps hier */ ]
}
```

WICHTIG: Analysiere die User-Anfrage und wähle die PASSENDE Variante!
PROMPT;
    }
}
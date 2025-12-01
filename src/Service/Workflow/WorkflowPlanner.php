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

            if ($step['type'] === 'tool_call' && $step['tool'] === 'send_email') {
                $step['parameters'] = $this->normalizeEmailAttachments($step['parameters']);
            }
        }

        return $plan;
    }

    private function normalizeEmailAttachments(array $params): array
    {
        if (!isset($params['attachments'])) {
            return $params;
        }

        $attachments = $params['attachments'];

        if (is_string($attachments)) {
            $decoded = json_decode($attachments, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $attachments = $decoded;
            } else {
                $attachments = [$attachments];
            }
        }

        if (is_array($attachments)) {
            $normalized = [];
            foreach ($attachments as $attachment) {
                if (is_string($attachment)) {
                    $normalized[] = [
                        'type' => 'document_id',
                        'value' => $attachment
                    ];
                } elseif (is_array($attachment)) {
                    if (!isset($attachment['type'])) {
                        $attachment['type'] = 'document_id';
                    }
                    $normalized[] = $attachment;
                }
            }
            $attachments = $normalized;
        }

        $params['attachments'] = json_encode($attachments, JSON_UNESCAPED_UNICODE);

        return $params;
    }

    private function inferOutputFormat(array $currentStep, array $allSteps, int $currentIndex): ?array
    {
        $requiredFields = [];

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

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{.*?\"steps\"\s*:\s*\[.*?\]\s*\}/s', $content, $matches)) {
            $json = $matches[0];
        } else {
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

        $json = preg_replace('/\/\*(.*?)\*\//s', '', $json);
        $json = preg_replace('/\/\/.*$/m', '', $json);
        $json = preg_replace('/,\s*([\]\}])/', '$1', $json);
        
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
Du bist ein intelligenter Workflow-Planer. Erstelle IMMER EFFIZIENTE, OPTIMIERTE, strukturierte, ausführbare Workflows basierend auf der User-Anfrage.

🎯 KRITISCHE REGELN:

1. **NUTZE USER-EINGABEN**: Wenn der User "Job als Entwickler in Lübeck" sagt, dann SPRINGE DIREKT zur Jobsuche mit diesen Werten!
2. **KEINE UNNÖTIGEN STEPS**: Überspringe Dokument-Analyse wenn 'was' und 'wo' bereits bekannt sind
3. **INTELLIGENTE REIHENFOLGE**: 
   - User gibt Job+Ort an → Jobsuche → Firmenkontakte → Lebenslauf laden → Anschreiben → E-Mail
   - User gibt NUR "bewerbe dich" → Lebenslauf laden → Job-Parameter extrahieren → Jobsuche → Rest
4. **Output-Format**:  enthält die EXAKTEN Feldnamen, die nachfolgende Steps benötigen
5. **Bestätigung**: requires_confirmation: true NUR für E-Mails
6. Verwende {{step_N.result.FELDNAME}} um auf Felder zuzugreifen

STEP-TYPES:
- tool_call: Ruft ein Tool auf
- analysis: Analysiert Daten → MUSS output_format haben
- decision: Trifft Entscheidung → MUSS output_format haben
- notification: Sendet Nachricht

📋 VERFÜGBARE TOOLS:

**Job-Suche:**
-  job_search: Sucht Stellenangebote (Parameter: what, where, radius)
  - Beispiel: {"what": "Entwickler", "where": "Lübeck", "radius": 25}
- company_career_contact_finder: Findet Karriere-Kontakte (Parameter: company_name)

**Dokumente:**
- user_document_search: Sucht Dokumente (Parameter: searchTerm, category)
- user_document_read: Liest Dokument (Parameter: identifier)
- user_document_list: Listet Dokumente (Parameter: category)

**Kommunikation:**
- send_email: Versendet E-Mail (Parameter: to, subject, body, attachments)
  - body: Kann langen Text enthalten - KEIN PDF nötig!
  - attachments: Format: ["{{step_N.result.resume_id}}"] oder ["3"]

**Web:**
- google_search: Google-Suche (Parameter: query)
- web_scraper: Scraped Webseite (Parameter: url)

🎨 OPTIMIERTE BEWERBUNGS-WORKFLOWS:

**Variante A: User gibt Job-> what und Ort-> where an (BEVORZUGT)**
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

**Variante B: User sagt nur "bewerbe dich", "suche mir einen Job", "ich will mich beruflich verändern (ohne Job/Ort)**
```json
{
  "steps": [
    {
      "type": "tool_call",
      "tool": "user_document_list",
      "description": "Liste Dokumente",
      "parameters": {}
    },
    {
      "type": "analysis",
      "description": "Identifiziere Lebenslauf",
      "output_format": {
        "resume_id": "string"
      }
    },
    {
      "type": "tool_call",
      "tool": "user_document_read",
      "description": "Lese Lebenslauf",
      "parameters": {
        "identifier": "{{step_2.result.resume_id}}"
      }
    },
    {
      "type": "analysis",
      "description": "Extrahiere Job-Parameter aus Lebenslauf",
      "output_format": {
        "job_title": "string",
        "job_location": "string"
      }
    },
    {
      "type": "tool_call",
      "tool": "job_search",
      "description": "Suche Jobs mit extrahierten Parametern",
      "parameters": {
        "what": "{{step_4.result.job_title}}",
        "where": "{{step_4.result.job_location}}",
        "radius": 25
      }
    },
    {
      "type": "analysis",
      "description": "Wähle besten Job",
      "output_format": {
        "job_title": "string",
        "company_name": "string",
        "job_url": "string"
      }
    },
    {
      "type": "tool_call",
      "tool": "company_career_contact_finder",
      "parameters": {
        "company_name": "{{step_6.result.company_name}}"
      }
    },
    {
      "type": "analysis",
      "description": "Erstelle Anschreiben",
      "output_format": {
        "cover_letter_text": "string"
      }
    },
    {
      "type": "tool_call",
      "tool": "send_email",
      "requires_confirmation": true,
      "parameters": {
        "to": "{{step_7.result.application_email|step_7.result.general_email}}",
        "subject": "Bewerbung als {{step_6.result.job_title}}",
        "body": "{{step_8.result.cover_letter_text}}",
        "attachments": ["{{step_2.result.resume_id}}"]
      }
    }
  ]
}
```

⚡ OPTIMIERUNGSREGELN:

1. **Erkenne explizite Parameter**:
   - "als Entwickler in Lübeck" → what="Entwickler", where="Lübeck"
   - nutze Variante A immer wenn möglich, nur wenn keine Parameter → Variante B

2. **Minimale Steps**:
   - Mit Job+Ort: 8 Steps (Suche → Auswahl → Kontakte → Lebenslauf → Anschreiben → E-Mail)
   - Ohne Parameter: +2-3 Steps für Extraktion

3. **KEINE Search-Varianten-Generation**:
   - Wenn User konkrete Eingaben macht, nutze diese DIREKT
   - Keine generate_search_variants, keine Retry-Loops

4. **Text direkt in E-Mail**:
   - Anschreiben im body, KEIN PDF
   - Nur Lebenslauf als Anhang

5. **Ein Job = Ein Anschreiben**:
   - Wähle EINEN besten Job aus
   - KEINE Iterationen über multiple Jobs

5. **das request_tool_development Tool wird nur dann verwendet, wenn es keine andere Möglichkeit gibt das anliegen zu bearbeiten**

OUTPUT-FORMAT (NUR JSON):
```json
{
  "steps": [...]
}
```

WICHTIG: Analysiere die User-Anfrage und erkenne vorhandene Parameter!
PROMPT;
    }
}
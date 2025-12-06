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

/**
 * EINZIGER Workflow-Planner im System
 * 
 * Verantwortlichkeiten:
 * - Analysiert User-Intent
 * - Prüft verfügbare Daten (Dokumente, Parameter)
 * - Erstellt Workflow mit Steps
 * - Validiert Plan
 */
final class WorkflowPlanner
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private Security $security
    ) {}

    /**
     * Hauptmethode: Erstellt Workflow aus User-Intent
     */
    public function createWorkflow(string $intent, string $sessionId): Workflow
    {
        /** @var User $user */
        $user = $this->security->getUser();
        
        if (!$user) {
            throw new \RuntimeException('User authentication required');
        }

        // 1. Analysiere verfügbare Daten
        $context = $this->analyzeContext($intent, $user);
        
        // 2. Validiere: Dokumente vorhanden?
        if (!($context['has_documents'] ?? false)) {
            throw new \RuntimeException(
                'Keine Dokumente gefunden. Bitte lade zuerst einen Lebenslauf hoch.'
            );
        }
        
        $this->logger->info('Context analyzed', [
            'session' => $sessionId,
            'document_count' => $context['document_count'],
            'has_job_params' => $context['has_job_what'] && $context['has_job_where']
        ]);

        // 3. Erstelle Plan via Agent
        $plan = $this->createPlan($intent, $context);
        
        // 4. Validiere Plan
        $this->validatePlan($plan);

        // 5. Baue Workflow-Objekt
        $workflow = new Workflow();
        $workflow->setSessionId($sessionId);
        $workflow->setUserIntent($intent);
        $workflow->setStatus('created');
        $workflow->setUser($user);
        $workflow->requireApproval();

        // 6. Erstelle Steps
        $this->createSteps($workflow, $plan);

        // 7. Persistiere
        $this->em->persist($workflow);
        $this->em->flush();

        $this->logger->info('Workflow created', [
            'workflow_id' => $workflow->getId(),
            'steps_count' => $workflow->getSteps()->count()
        ]);

        return $workflow;
    }

    /**
     * Analysiert verfügbare Daten und Context
     */
    private function analyzeContext(string $intent, User $user): array
    {
        $context = [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'intent' => $intent
        ];

        // Prüfe verfügbare Dokumente
        $documentRepo = $this->em->getRepository(\App\Entity\UserDocument::class);
        $documents = $documentRepo->findNonSecretByUser($user);
        
        $context['has_documents'] = !empty($documents);
        $context['document_count'] = count($documents);
        
        if (!empty($documents)) {
            $context['document_ids'] = array_map(fn($d) => $d->getId(), $documents);
            
            // Gruppiere nach Kategorien
            $byCategory = [];
            foreach ($documents as $doc) {
                $category = $doc->getCategory() ?? 'other';
                $byCategory[$category][] = [
                    'id' => $doc->getId(),
                    'name' => $doc->getDisplayName() ?? $doc->getOriginalFilename()
                ];
            }
            $context['documents_by_category'] = $byCategory;
        }

        // Extrahiere Job-Parameter aus Intent
        $jobParams = $this->extractJobParameters($intent);
        $context = array_merge($context, $jobParams);

        return $context;
    }

    /**
     * Extrahiert Job-Suchparameter (was, wo) aus Intent
     */
    private function extractJobParameters(string $intent): array
    {
        $params = [
            'has_job_what' => false,
            'has_job_where' => false
        ];

        // Was-Extraktion
        $whatPatterns = [
            '/als\s+([a-zäöüß\s]+?)(?:\s+in|\s+bei|\s+$)/iu',
            '/job\s+als\s+([a-zäöüß\s]+)/iu',
            '/stelle\s+als\s+([a-zäöüß\s]+)/iu',
        ];

        foreach ($whatPatterns as $pattern) {
            if (preg_match($pattern, $intent, $matches)) {
                $params['job_what'] = trim($matches[1]);
                $params['has_job_what'] = true;
                break;
            }
        }

        // Wo-Extraktion
        $wherePatterns = [
            '/in\s+([a-zäöüß\s]+?)(?:\s+als|\s+bewerben|\s+$)/iu',
            '/bei\s+(?:firmen\s+)?(?:in|aus)\s+([a-zäöüß\s]+)/iu',
        ];

        foreach ($wherePatterns as $pattern) {
            if (preg_match($pattern, $intent, $matches)) {
                $params['job_where'] = trim($matches[1]);
                $params['has_job_where'] = true;
                break;
            }
        }

        return $params;
    }

    /**
     * Erstellt Plan via Agent
     */
    private function createPlan(string $intent, array $context): array
    {
        $prompt = $this->buildPlanningPrompt($intent, $context);

        $messages = new MessageBag(
            Message::forSystem($prompt),
            Message::ofUser($intent)
        );

        $result = $this->agent->call($messages);
        
        return $this->parsePlan($result->getContent());
    }

    /**
     * Baut Planning-Prompt
     */
    private function buildPlanningPrompt(string $intent, array $context): string
    {
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        return <<<PROMPT
Du bist ein Workflow-Planer. Erstelle strukturierte, ausführbare Workflows in allen dir gestellten aufgaben.

🎯 VERFÜGBARE TOOLS:
**Internet-Suche:**
- google_search  {"what": "arzt", "where": "Lübeck", "radius": 25}
- web_scraper {"url": "https://www.arzt.de" }

**Planung und Organisation:**
- google_calendar_create_event

**Job-Suche:**
- job_search: {"what": "Entwickler", "where": "Lübeck", "radius": 25}
- company_career_contact_finder: {"company_name": "Firma"}

**Dokumente:**
- user_document_search: {"searchTerm": "Lebenslauf"}
- user_document_read: {"identifier": "doc_id"}
- user_document_list: {"category": "all"}

**Kommunikation:**
- send_email: {
    "to": "email@example.com",
    "subject": "Betreff",
    "body": "Vollständiger Text (kein PDF nötig!)",
    "attachments": ["{{step_N.result.resume_id}}"]
  }

**WICHTIG für E-Mails:**
- Pipe-Fallback verwenden: {{step_X.result.application_email|step_X.result.general_email}}
- Body enthält vollständigen Text (kein PDF generieren!)
- Attachments sind Dokument-IDs

🎨 WORKFLOW-MUSTER - Jobsuche:

**Mit Job+Ort (User gibt an):**
```json
{
  "steps": [
    {"type": "tool_call", "tool": "job_search", "parameters": {"what": "Job", "where": "Ort"}},
    {"type": "decision", "description": "Wähle besten Job", "requires_confirmation": true,
     "output_format": {"job_title": "string", "company_name": "string", "job_url": "string"}},
    {"type": "tool_call", "tool": "company_career_contact_finder", 
     "parameters": {"company_name": "{{step_2.result.company_name}}"}},
    {"type": "tool_call", "tool": "user_document_search", 
     "parameters": {"searchTerm": "Lebenslauf"}},
    {"type": "analysis", "description": "Extrahiere Lebenslauf-ID",
    "output_format": {"resume_id": "string"}},
    {"type": "tool_call", "tool": "user_document_read", "description": "Lese Lebenslauf",
      "parameters": { "identifier": "{{step_5.result.resume_id}}" }    },
    {"type": "analysis", "description": "Erstelle Bewerbungstext auf Grundlage des Lebenslaufs und der Jobbeschreibung",
     "output_format": {"cover_letter_text": "string"}},
    {"type": "tool_call", "tool": "send_email", "requires_confirmation": true,
     "parameters": {
       "to": "{{step_3.result.result.application_email|step_3.result.result.general_email}}",
       "subject": "Bewerbung als {{step_2.result.job_title}}",
       "body": "{{step_6.result.cover_letter_text}}",
       "attachments": ["{{step_5.result.resume_id}}"]
     }}
  ]
}
```

**Ohne Job-Parameter:**
- Zuerst Dokument laden
- Job-Parameter aus Lebenslauf extrahieren und  
- Dann job_search mit extrahierten Parametern What
- Rest wie oben

**Beispiel Wokflow für Terminplanung:**
- google_search nach "Arzt in Lübeck"
- web_scraper durchsuche Praxiswebzeiten nach Kontaktinfo (E-Mail)
- Auswahl der besten Praxis (requires_confirmation=true)
- text generierung für Terminanfrage
- send_email an Praxis (requires_confirmation=true)


⚡ REGELN:
1. Nutze User-Eingaben direkt wenn vorhanden
2. Job-Auswahl MUSS requires_confirmation=true haben
3. E-Mails MÜSSEN requires_confirmation=true haben
4. Verwende IMMER Pipe-Fallback für E-Mails
5. Antworte NUR mit JSON (kein Markdown-Fencing!)



CONTEXT:
{$contextJson}

OUTPUT (NUR JSON):
PROMPT;
    }

    /**
     * Parsed Plan-Response
     */
    private function parsePlan(string $content): array
    {
        $this->logger->debug('Parsing plan', [
            'content_preview' => substr($content, 0, 200)
        ]);

        // Extrahiere JSON
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{.*?"steps"\s*:\s*\[.*?\]\s*\}/s', $content, $matches)) {
            $json = $matches[0];
        } else {
            throw new \RuntimeException('Kein gültiges JSON im Plan gefunden');
        }

        // Bereinige JSON
        $json = preg_replace('/\/\*(.*?)\*\//s', '', $json);
        $json = preg_replace('/\/\/.*$/m', '', $json);
        $json = preg_replace('/,\s*([\]\}])/', '$1', $json);

        $plan = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON Parse Error: ' . json_last_error_msg());
        }

        if (!isset($plan['steps']) || !is_array($plan['steps'])) {
            throw new \RuntimeException('Plan enthält kein steps-Array');
        }

        return $plan;
    }

    /**
     * Validiert Plan
     */
    private function validatePlan(array $plan): void
    {
        if (empty($plan['steps'])) {
            throw new \RuntimeException('Plan enthält keine Steps');
        }

        foreach ($plan['steps'] as $index => $step) {
            // Erforderliche Felder
            if (!isset($step['type'])) {
                throw new \RuntimeException("Step {$index}: Fehlendes Feld 'type'");
            }

            $validTypes = ['tool_call', 'analysis', 'decision', 'notification'];
            if (!in_array($step['type'], $validTypes)) {
                throw new \RuntimeException("Step {$index}: Ungültiger type '{$step['type']}'");
            }

            // tool_call braucht tool
            if ($step['type'] === 'tool_call' && empty($step['tool'])) {
                throw new \RuntimeException("Step {$index}: tool_call ohne 'tool'");
            }

            // analysis/decision sollten output_format haben
            if (in_array($step['type'], ['analysis', 'decision']) && !isset($step['output_format'])) {
                $this->logger->warning("Step {$index}: {$step['type']} ohne output_format");
            }
        }
    }

    /**
     * Erstellt Steps aus Plan
     */
    private function createSteps(Workflow $workflow, array $plan): void
    {
        foreach ($plan['steps'] as $index => $stepData) {
            $step = new WorkflowStep();
            $step->setWorkflow($workflow);
            $step->setStepNumber($index + 1);
            $step->setStepType($stepData['type']);
            $step->setDescription($stepData['description'] ?? "Step {$index}");
            $step->setToolName($stepData['tool'] ?? null);
            $step->setToolParameters($stepData['parameters'] ?? []);
            $step->setRequiresConfirmation($stepData['requires_confirmation'] ?? false);
            $step->setStatus('pending');

            if (isset($stepData['output_format'])) {
                $step->setExpectedOutputFormat($this->normalizeOutputFormat($stepData['output_format']));
            }

            $workflow->addStep($step);
        }
    }

    /**
     * Normalisiert output_format
     */
    private function normalizeOutputFormat(mixed $format): array
    {
        if (!is_array($format)) {
            return ['type' => 'object', 'fields' => ['result' => 'string']];
        }

        // Wenn nur fields, wrappe in type: object
        if (isset($format['fields']) && !isset($format['type'])) {
            $format['type'] = 'object';
        }

        // Wenn nur Felder ohne Struktur
        if (!isset($format['type']) && !isset($format['fields'])) {
            return ['type' => 'object', 'fields' => $format];
        }

        return $format;
    }
}
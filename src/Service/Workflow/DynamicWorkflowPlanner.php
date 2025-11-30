<?php
// src/Service/Workflow/DynamicWorkflowPlanner.php

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
 * Dynamischer Workflow-Planner mit Self-Healing
 * - Erkennt fehlende Daten automatisch
 * - Plant Steps basierend auf tatsächlich verfügbaren Daten
 * - Passt sich an Fehler an
 * 
 * 📂 Unterstützte Dokument-Kategorien:
 * - attachment: Dateien die als E-Mail-Anhänge versendet werden (oft Lebensläufe!)
 * - template: Vorlagen
 * - reference: Referenzen
 * - media: Medien-Dateien
 * - generated: Automatisch generierte Dokumente
 * - other: Sonstige
 */
final class DynamicWorkflowPlanner
{
    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private Security $security
    ) {}

    public function createAdaptiveWorkflow(string $userIntent, string $sessionId): Workflow
    {
        /** @var User $user */
        $user = $this->security->getUser();

        // ✅ 1. Analysiere User-Intent UND Datenbank-Status
        $context = $this->analyzeContext($userIntent, $user);
        
        // ✅ 2. KRITISCH: Verifiziere Context-Daten
        $context = $this->verifyContextData($context, $user);
        
        // 🛑 Wenn keine Dokumente vorhanden: Werfe verständlichen Fehler
        if (isset($context['error']) && $context['error'] === 'no_documents') {
            throw new \RuntimeException(
                'Keine Dokumente gefunden. Bitte lade zuerst einen Lebenslauf hoch, ' .
                'bevor du einen Job-Such-Workflow startest.'
            );
        }
        
        $this->logger->info('Context analyzed and verified', [
            'available_data' => array_keys($context),
            'has_documents' => $context['has_documents'] ?? false,
            'document_count' => $context['document_count'] ?? 0,
            'intent' => $userIntent
        ]);

        // 3. Erstelle adaptiven Plan
        $plan = $this->createAdaptivePlan($userIntent, $context);

        // 4. Baue Workflow
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

        return $workflow;
    }

    /**
     * Analysiert verfügbare Daten und Kontext
     * ✅ UPDATED: Prüft ALLE Dokumente, nicht nur category="resume"
     */
    private function analyzeContext(string $intent, User $user): array
    {
        $context = [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'intent' => $intent
        ];

        // ✅ Prüfe ALLE nicht-geheimen Dokumente (alle Kategorien außer isSecret=true)
        $documentRepo = $this->em->getRepository(\App\Entity\UserDocument::class);
        $allDocs = $documentRepo->findNonSecretByUser($user);
        
        if (!empty($allDocs)) {
            $context['has_documents'] = true;
            $context['document_count'] = count($allDocs);
            $context['document_ids'] = array_map(fn($d) => $d->getId(), $allDocs);
            
            // Gruppiere nach Kategorien für besseren Überblick
            $docsByCategory = [];
            foreach ($allDocs as $doc) {
                $category = $doc->getCategory() ?? 'other';
                if (!isset($docsByCategory[$category])) {
                    $docsByCategory[$category] = [];
                }
                $docsByCategory[$category][] = [
                    'id' => $doc->getId(),
                    'name' => $doc->getDisplayName() ?? $doc->getOriginalFilename()
                ];
            }
            $context['documents_by_category'] = $docsByCategory;
            
            // ✅ Besonders: attachment-Dokumente (für Bewerbungen)
            $attachments = array_filter($allDocs, fn($d) => $d->getCategory() === 'attachment');
            $context['has_attachments'] = !empty($attachments);
            $context['attachment_count'] = count($attachments);
        } else {
            $context['has_documents'] = false;
            $context['document_count'] = 0;
        }

        // Extrahiere Job-Suchparameter aus Intent
        $jobParams = $this->extractJobParametersFromIntent($intent);
        $context = array_merge($context, $jobParams);

        return $context;
    }

    /**
     * ✅ SIMPLIFIED: Verifiziert Context - prüft nur noch ob überhaupt Dokumente da sind
     * 🔒 Filtert automatisch isSecret=true Dokumente aus
     */
    private function verifyContextData(array $context, User $user): array
    {
        $documentRepo = $this->em->getRepository(\App\Entity\UserDocument::class);
        
        // ✅ Lade ALLE nicht-geheimen Dokumente (alle Kategorien)
        $allDocs = $documentRepo->findNonSecretByUser($user);
        
        $this->logger->info('Documents verified', [
            'user_id' => $user->getId(),
            'total_count' => count($allDocs),
            'by_category' => array_reduce($allDocs, function($carry, $doc) {
                $cat = $doc->getCategory() ?? 'other';
                $carry[$cat] = ($carry[$cat] ?? 0) + 1;
                return $carry;
            }, [])
        ]);

        // 🛑 CRITICAL: Keine Dokumente vorhanden
        if (empty($allDocs)) {
            $this->logger->error('No documents found at all for user', [
                'user_id' => $user->getId()
            ]);
            
            $context['has_documents'] = false;
            $context['document_count'] = 0;
            $context['error'] = 'no_documents';
            
            return $context;
        }

        // Update Context mit ECHTEN Daten
        $context['has_documents'] = true;
        $context['document_count'] = count($allDocs);
        $context['document_ids'] = array_map(fn($d) => $d->getId(), $allDocs);
        
        // ✅ Detaillierte Dokument-Infos für Agent
        $context['documents_details'] = array_map(function($doc) {
            return [
                'id' => $doc->getId(),
                'name' => $doc->getDisplayName() ?? $doc->getOriginalFilename(),
                'filename' => $doc->getOriginalFilename(),
                'category' => $doc->getCategory() ?? 'other',
                'created_at' => $doc->getCreatedAt()->format('Y-m-d'),
                'has_extracted_text' => !empty($doc->getExtractedText()),
                'is_secret' => $doc->isSecret()
            ];
        }, $allDocs);

        // ✅ Wenn was/wo fehlt: Agent muss aus Dokumenten extrahieren
        if ($context['has_documents'] && 
            (!isset($context['has_job_what']) || !$context['has_job_what'] || 
             !isset($context['has_job_where']) || !$context['has_job_where'])) {
            
            $this->logger->info('User has documents but missing job parameters - will extract from documents', [
                'has_job_what' => $context['has_job_what'] ?? false,
                'has_job_where' => $context['has_job_where'] ?? false
            ]);
            
            $context['requires_document_analysis'] = true;
        }

        return $context;
    }

    /**
     * Extrahiert Job-Suchparameter aus dem Intent
     */
    private function extractJobParametersFromIntent(string $intent): array
    {
        $params = [
            'has_job_what' => false,
            'has_job_where' => false
        ];

        $intentLower = strtolower($intent);

        // Was-Extraktion (verschiedene Patterns)
        $whatPatterns = [
            '/als\s+([a-zäöüß\s]+?)(?:\s+in|\s+bei|\s+$)/iu',
            '/job\s+als\s+([a-zäöüß\s]+)/iu',
            '/stelle\s+als\s+([a-zäöüß\s]+)/iu',
            '/position\s+als\s+([a-zäöüß\s]+)/iu',
        ];

        foreach ($whatPatterns as $pattern) {
            if (preg_match($pattern, $intent, $matches)) {
                $params['job_what'] = trim($matches[1]);
                $params['has_job_what'] = true;
                break;
            }
        }

        // Wo-Extraktion (verschiedene Patterns)
        $wherePatterns = [
            '/in\s+([a-zäöüß\s]+?)(?:\s+als|\s+bewerben|\s+$)/iu',
            '/bei\s+(?:firmen\s+)?(?:in|aus)\s+([a-zäöüß\s]+)/iu',
            '/\s+([a-zäöüß]+)\s+job/iu', // "Hamburg job"
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
     * Erstellt adaptiven Plan basierend auf VERIFIZIERTEN Daten
     */
    private function createAdaptivePlan(string $intent, array $context): array
    {
        $prompt = $this->buildAdaptivePlanningPrompt($intent, $context);

        $messages = new MessageBag(
            Message::forSystem($prompt),
            Message::ofUser($intent)
        );

        $result = $this->agent->call($messages);
        $plan = $this->parseWorkflowPlan($result->getContent());

        return $plan;
    }

    /**
     * ✅ UPDATED: Baut adaptiven Planning-Prompt für ALLE Dokument-Kategorien
     */
    private function buildAdaptivePlanningPrompt(string $intent, array $context): string
    {
        $contextInfo = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        // Lade adaptive Planning Instructions
        
        return <<<PROMPT
          
            ## 🎯 Ziel
            Erstelle **selbst-heilende Workflows** die sich automatisch an fehlende Daten und Fehler anpassen.

            ## 🔄 Kern-Konzepte

            ### 1. Smart Retry mit Varianten-Generation

            Statt fixer Retry-Steps: **Generiere Suchvarianten vorher und iteriere**

            ```json
            {
            "steps": [
                {
                "type": "analysis",
                "description": "Extrahiere Job-Parameter aus Lebenslauf",
                "output_format": {
                    "type": "object",
                    "fields": {
                    "job_title": "string",
                    "job_location": "string",
                    "skills": "array"
                    }
                }
                },
                {
                "type": "tool_call",
                "tool": "generate_search_variants",
                "description": "Generiere Smart-Search-Varianten (Jobtitel-Fallbacks + Radius-Eskalation)",
                "parameters": {
                    "base_title": "{{step_4.result.job_title}}",
                    "base_location": "{{step_4.result.job_location}}",
                    "skills": "{{step_4.result.skills}}"
                }
                },
                {
                "type": "tool_call",
                "tool": "job_search",
                "description": "Versuch 1: Suche mit erster Variante",
                "parameters": {
                    "what": "{{search_variants_list[0].what}}",
                    "where": "{{search_variants_list[0].where}}",
                    "radius": "{{search_variants_list[0].radius}}"
                }
                },
                {
                "type": "decision",
                "description": "Prüfe Suchergebnis - ist Qualität gut genug oder retry?",
                "output_format": {
                    "type": "object",
                    "fields": {
                    "has_results": "boolean",
                    "quality_score": "integer",
                    "should_retry": "boolean",
                    "next_variant_index": "integer"
                    }
                }
                },
                {
                "type": "tool_call",
                "tool": "job_search",
                "description": "Versuch 2: Falls nötig mit nächster Variante",
                "skip_if": "{{step_7.result.should_retry}} == false",
                "parameters": {
                    "what": "{{search_variants_list[{{step_7.result.next_variant_index}}].what}}",
                    "where": "{{search_variants_list[{{step_7.result.next_variant_index}}].where}}",
                    "radius": "{{search_variants_list[{{step_7.result.next_variant_index}}].radius}}"
                }
                }
            ]
            }
            ```

            ### 2. Platzhalter-System

            **Unterstützte Patterns:**

            - **Nested Access:** `{{step_5.result.jobs[0].company}}`
            - **Array-Index:** `{{search_variants_list[0].what}}`
            - **Fallback-Chain:** `{{step_3.result.resume_id||step_2.result.doc_id||"default"}}`
            - **Conditional Skip:** `skip_if: "{{step_X.result.has_results}} == true"`

            ### 3. Context-Struktur

            Executor baut folgenden Context auf:

            ```json
            {
            "step_1": {
                "result": { "documents": [...] }
            },
            "step_2": {
                "result": { "resume_id": "3" }
            },
            "search_variants_list": [
                {
                "strategy": "title_location_radius",
                "priority": 0,
                "what": "Geschäftsführer",
                "where": "Sereetz",
                "radius": 0,
                "description": "Geschäftsführer in Sereetz"
                },
                {
                "strategy": "title_location_radius",
                "priority": 1,
                "what": "Geschäftsführer",
                "where": "Sereetz",
                "radius": 10,
                "description": "Geschäftsführer in Sereetz (+10km)"
                },
                {
                "strategy": "title_location_radius",
                "priority": 10,
                "what": "Niederlassungsleiter",
                "where": "Sereetz",
                "radius": 0,
                "description": "Niederlassungsleiter in Sereetz"
                }
            ],
            "search_variants_count": 15
            }
            ```

            ## 📋 Workflow-Templates

            ### Template A: Job-Suche mit Smart Retry

            ```json
            {
            "steps": [
                // 1. Dokumente listen
                {
                "type": "tool_call",
                "tool": "user_document_list",
                "description": "Liste ALLE Dokumente",
                "parameters": {}
                },
                
                // 2. Lebenslauf identifizieren
                {
                "type": "analysis",
                "description": "Identifiziere Lebenslauf aus Liste",
                "output_format": {
                    "type": "object",
                    "fields": {
                    "resume_id": "string",
                    "confidence": "string"
                    }
                }
                },
                
                // 3. Lebenslauf lesen (mit Fallback)
                {
                "type": "tool_call",
                "tool": "user_document_read",
                "description": "Lese Lebenslauf",
                "parameters": {
                    "identifier": "{{step_2.result.resume_id}}"
                }
                },
                
                // 4. KRITISCH: Parameter extrahieren mit strengem Format
                {
                "type": "analysis",
                "description": "Extrahiere Job-Parameter - GIB KONKRETE WERTE ZURÜCK",
                "output_format": {
                    "type": "object",
                    "fields": {
                    "job_title": "string",
                    "job_location": "string",
                    "skills": "array",
                    "experience_years": "string"
                    }
                },
                "validation": {
                    "required": ["job_title", "job_location"],
                    "min_length": {
                    "job_title": 3,
                    "job_location": 3
                    }
                }
                },
                
                // 5. Generiere Suchvarianten
                {
                "type": "tool_call",
                "tool": "generate_search_variants",
                "description": "Generiere intelligente Suchvarianten",
                "parameters": {
                    "base_title": "{{step_4.result.job_title}}",
                    "base_location": "{{step_4.result.job_location}}",
                    "skills": "{{step_4.result.skills}}"
                }
                },
                
                // 6-10: Iterative Job-Suche
                {
                "type": "tool_call",
                "tool": "job_search",
                "description": "Job-Suche Versuch 1",
                "parameters": {
                    "what": "{{search_variants_list[0].what}}",
                    "where": "{{search_variants_list[0].where}}"
                }
                },
                {
                "type": "decision",
                "description": "Evaluiere Ergebnis Versuch 1",
                "output_format": {
                    "type": "object",
                    "fields": {
                    "has_results": "boolean",
                    "quality_score": "integer",
                    "should_retry": "boolean"
                    }
                }
                },
                {
                "type": "tool_call",
                "tool": "job_search",
                "description": "Job-Suche Versuch 2 (falls nötig)",
                "skip_if": "{{step_7.result.should_retry}} == false",
                "parameters": {
                    "what": "{{search_variants_list[1].what}}",
                    "where": "{{search_variants_list[1].where}}"
                }
                },
                {
                "type": "decision",
                "description": "Wähle beste Ergebnisse aus allen Versuchen",
                "output_format": {
                    "type": "object",
                    "fields": {
                    "final_job_title": "string",
                    "final_company": "string",
                    "final_job_url": "string"
                    }
                }
                }
            ]
            }
            ```

            ## 🔧 Tool: generate_search_variants

            Wird vom Executor erkannt und ruft `SmartJobSearchStrategy` auf:

            **Input:**
            ```json
            {
            "base_title": "Geschäftsführer",
            "base_location": "Sereetz",
            "skills": ["Personalführung", "PHP", "Marketing"]
            }
            ```

            **Output in Context:**
            ```json
            {
            "search_variants_list": [
                {"what": "Geschäftsführer", "where": "Sereetz", "radius": 0, "priority": 0},
                {"what": "Geschäftsführer", "where": "Sereetz", "radius": 10, "priority": 1},
                {"what": "Geschäftsführer", "where": "Sereetz", "radius": 20, "priority": 2},
                {"what": "Niederlassungsleiter", "where": "Sereetz", "radius": 0, "priority": 10},
                {"what": "Betriebsleiter", "where": "Sereetz", "radius": 0, "priority": 20},
                {"what": "Personalführung", "where": "Sereetz", "radius": 0, "priority": 100}
            ]
            }
            ```

            ## ✅ Validation Rules

            Agent MUSS diese Rules prüfen:

            1. **Keine leeren Parameter-Extraktion**
            - Wenn analysis `job_title: ""` liefert → FEHLER
            - Agent muss aus Dokumenten konkrete Werte extrahieren

            2. **Platzhalter müssen auflösbar sein**
            - Vor Tool-Call: Prüfe ob alle `{{...}}` auflösbar
            - Falls nicht: Füge Fallback-Step ein

            3. **Decision-Steps müssen boolean flags liefern**
            - `has_results`, `should_retry` PFLICHT
            - Keine Text-Antworten ohne Struktur

            ## 🚨 Error Recovery

            Bei leeren Extraktionen:

            ```json
            {
            "type": "analysis",
            "description": "FALLBACK: Extrahiere aus GESAMTEM Lebenslauf-Text konkrete Jobtitel",
            "force_concrete_extraction": true,
            "output_format": {
                "type": "object",
                "fields": {
                "most_recent_job_title": "string",
                "most_recent_employer": "string",
                "work_location": "string"
                }
            }
            }
            ```

            ## 💡 Best Practices

            1. **Varianten VOR Loop generieren** - Nicht in jedem Retry neu berechnen
            2. **Quality-Score nutzen** - Nicht nur `job_count > 0`
            3. **Max 5 Retry-Versuche** - Dann beste auswählen
            4. **Skip-Logic verwenden** - Spart unnötige Tool-Calls
            5. **Fallback-Chains** - `{{step_3.result||step_2.result||"fallback"}}`


            📋 VERFÜGBARER CONTEXT (VERIFIZIERT):
            ```json
            {$contextInfo}
            ```

            USER INTENT:
            {$intent}

            OUTPUT (NUR JSON):
            ```json
            {
            "steps": [...]
            }
            ```
            PROMPT;
    }

    /**
     * Erstellt Step mit verbesserter Fehlerbehandlung
     */
    private function createStep(Workflow $workflow, int $stepNumber, array $stepData): WorkflowStep
    {
        // ✅ Validiere und normalisiere Step-Daten
        $stepData = $this->normalizeStepData($stepData, $stepNumber);

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

    /**
     * Normalisiert Step-Daten und füllt fehlende Felder
     */
    private function normalizeStepData(array $stepData, int $stepNumber): array
    {
        // Prüfe erforderliche Felder
        if (!isset($stepData['type'])) {
            throw new \RuntimeException("Step {$stepNumber}: Missing 'type' field");
        }

        // Setze Defaults für optionale Felder
        $stepData['description'] = $stepData['description'] ?? 
            $this->generateDescription($stepData);

        $stepData['parameters'] = $stepData['parameters'] ?? [];
        $stepData['requires_confirmation'] = $stepData['requires_confirmation'] ?? false;

        // Validiere Step-Type
        $validTypes = ['tool_call', 'analysis', 'decision', 'notification'];
        if (!in_array($stepData['type'], $validTypes)) {
            throw new \RuntimeException(
                "Step {$stepNumber}: Invalid type '{$stepData['type']}'. Must be one of: " . 
                implode(', ', $validTypes)
            );
        }

        // Bei tool_call: Tool muss gesetzt sein
        if ($stepData['type'] === 'tool_call' && empty($stepData['tool'])) {
            throw new \RuntimeException("Step {$stepNumber}: tool_call requires 'tool' field");
        }

        // Bei analysis/decision: output_format sollte gesetzt sein
        if (in_array($stepData['type'], ['analysis', 'decision'])) {
            if (!isset($stepData['output_format'])) {
                $this->logger->warning("Step {$stepNumber}: {$stepData['type']} without output_format", [
                    'step_data' => $stepData
                ]);
                
                // Erstelle Basic-Format
                $stepData['output_format'] = [
                    'type' => 'object',
                    'fields' => [
                        'result' => 'string'
                    ]
                ];
            }
        }

        return $stepData;
    }

    /**
     * Generiert automatisch eine Description basierend auf Step-Daten
     */
    private function generateDescription(array $stepData): string
    {
        $type = $stepData['type'];
        $tool = $stepData['tool'] ?? null;

        return match($type) {
            'tool_call' => $tool ? "Führe Tool '{$tool}' aus" : 'Tool-Aufruf',
            'analysis' => 'Analysiere Daten und extrahiere Informationen',
            'decision' => 'Treffe Entscheidung basierend auf Kontext',
            'notification' => 'Sende Benachrichtigung',
            default => 'Führe Workflow-Step aus'
        };
    }

    /**
     * Parsed Workflow-Plan mit besserer Fehlerbehandlung
     */
    private function parseWorkflowPlan(string $content): array
    {
        $this->logger->debug('Parsing workflow plan', [
            'content_preview' => substr($content, 0, 500)
        ]);

        // Versuche JSON zu extrahieren
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{.*?"steps"\s*:\s*\[.*?\]\s*\}/s', $content, $matches)) {
            $json = $matches[0];
        } elseif (preg_match('/\{.*?"error"\s*:/s', $content, $matches)) {
            // Error-Response gefunden
            $json = $matches[0];
        } else {
            $this->logger->error('Could not find JSON in response', [
                'content' => substr($content, 0, 1000)
            ]);
            throw new \RuntimeException('Could not parse workflow plan: No JSON found. Agent response: ' . substr($content, 0, 200));
        }

        // Bereinige JSON
        $json = preg_replace('/\/\*(.*?)\*\//s', '', $json); // Block comments
        $json = preg_replace('/\/\/.*$/m', '', $json);        // Line comments
        $json = preg_replace('/,\s*([\]\}])/', '$1', $json);  // Trailing commas

        $this->logger->debug('Cleaned JSON', [
            'json_preview' => substr($json, 0, 500)
        ]);

        $plan = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON parsing failed', [
                'error' => json_last_error_msg(),
                'json' => substr($json, 0, 1000)
            ]);
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        // ✅ Prüfe auf Error in Response
        if (isset($plan['error'])) {
            throw new \RuntimeException($plan['message'] ?? $plan['error']);
        }

        if (!isset($plan['steps']) || !is_array($plan['steps'])) {
            $this->logger->error('Missing steps array', [
                'plan_keys' => array_keys($plan)
            ]);
            throw new \RuntimeException('Missing steps array in plan');
        }

        if (empty($plan['steps'])) {
            throw new \RuntimeException('Plan contains no steps');
        }

        // ✅ Validiere und normalisiere alle Steps
        foreach ($plan['steps'] as $index => &$step) {
            try {
                $step = $this->validateAndNormalizeStep($step, $index);
            } catch (\Exception $e) {
                $this->logger->error('Step validation failed', [
                    'step_index' => $index,
                    'error' => $e->getMessage(),
                    'step_data' => $step
                ]);
                throw $e;
            }
        }

        $this->logger->info('Workflow plan parsed successfully', [
            'steps_count' => count($plan['steps'])
        ]);

        return $plan;
    }

    /**
     * Validiert und normalisiert einen einzelnen Step
     */
    private function validateAndNormalizeStep(array $step, int $index): array
    {
        // Erforderliche Felder
        if (!isset($step['type'])) {
            throw new \RuntimeException("Step {$index}: Missing required field 'type'");
        }

        // Setze Defaults
        $normalized = [
            'type' => $step['type'],
            'description' => $step['description'] ?? "Step {$index}: " . ($step['tool'] ?? $step['type']),
            'parameters' => $step['parameters'] ?? [],
            'requires_confirmation' => $step['requires_confirmation'] ?? false
        ];

        // Tool bei tool_call
        if ($step['type'] === 'tool_call') {
            if (!isset($step['tool'])) {
                throw new \RuntimeException("Step {$index}: tool_call requires 'tool' field");
            }
            $normalized['tool'] = $step['tool'];
        }

        // Output-Format bei analysis/decision
        if (in_array($step['type'], ['analysis', 'decision'])) {
            if (isset($step['output_format'])) {
                $normalized['output_format'] = $this->normalizeOutputFormat($step['output_format']);
            } else {
                $this->logger->warning("Step {$index}: {$step['type']} without output_format, adding default");
                $normalized['output_format'] = [
                    'type' => 'object',
                    'fields' => ['result' => 'string']
                ];
            }
        }

        return $normalized;
    }

    /**
     * Normalisiert output_format
     */
    private function normalizeOutputFormat(mixed $format): array
    {
        if (!is_array($format)) {
            return [
                'type' => 'object',
                'fields' => ['result' => 'string']
            ];
        }

        // Wenn nur fields angegeben, wrappe in type: object
        if (isset($format['fields']) && !isset($format['type'])) {
            $format['type'] = 'object';
        }

        // Wenn nur Felder ohne Struktur, konvertiere
        if (!isset($format['type']) && !isset($format['fields'])) {
            return [
                'type' => 'object',
                'fields' => $format
            ];
        }

        return $format;
    }
}
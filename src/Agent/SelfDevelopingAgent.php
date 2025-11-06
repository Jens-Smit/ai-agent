<?php
// src/Agent/SelfDevelopingAgent.php

namespace App\Agent;

use App\Entity\AgentTask;
use App\Repository\AgentTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Self-Developing AI Agent mit sicherer Tool-Generierung
 * 
 * Workflow:
 * 1. Analysiere User-Intent (z.B. "Termin vereinbaren")
 * 2. Prüfe vorhandene Tools/Services
 * 3. Falls fehlend: Generiere Service-Code
 * 4. Teste in Sandbox (Double-Sandboxing)
 * 5. Warte auf User-Approval
 * 6. Implementiere NACH Freigabe
 */
class SelfDevelopingAgent
{
    private const EXECUTOR_URL = 'http://tool_executor:8001';
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly AgentTaskRepository $taskRepository,
        private readonly LoggerInterface $logger,
        private readonly string $geminiApiKey,
        private readonly string $projectDir
    ) {}

    /**
     * Haupteinstieg: Verarbeitet User-Prompt
     */
    public function processPrompt(string $prompt, bool $autoApprove = false, array $config = []): string
    {
        $task = new AgentTask($prompt);
        $task->setStatus('processing');
        $task->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($task);
        $this->em->flush();

        $this->logger->info('Agent Task created', [
            'task_id' => $task->getId(),
            'prompt' => $prompt
        ]);

        // Starte Workflow asynchron (in Production via Messenger)
        try {
            $this->executeAgentWorkflow($task, $autoApprove);
        } catch (\Exception $e) {
            $this->logger->error('Agent workflow failed', [
                'task_id' => $task->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $task->setStatus('failed');
            $this->em->flush();
        }

        return $task->getId();
    }

    /**
     * Agent-Workflow: Intent-Analyse -> Tool-Check -> Code-Gen -> Test -> Review
     */
    private function executeAgentWorkflow(AgentTask $task, bool $autoApprove): void
    {
        try {
            // Schritt 1: Analysiere User-Intent mit Gemini
            $this->logger->info('Step 1: Analyzing user intent', ['task_id' => $task->getId()]);
            $analysis = $this->analyzeIntentWithLLM($task->getPrompt());

            if (empty($analysis)) {
                throw new \RuntimeException('LLM returned empty analysis');
            }

            $this->logger->info('Intent analysis complete', [
                'task_id' => $task->getId(),
                'analysis' => $analysis
            ]);

            // Schritt 2: Prüfe, ob benötigte Tools existieren
            $missingTools = $this->identifyMissingTools($analysis);

            if (empty($missingTools)) {
                // Alle Tools vorhanden - führe Aufgabe direkt aus
                $task->setStatus('completed');
                $task->setResult([
                    'message' => 'Alle benötigten Tools vorhanden',
                    'available_tools' => $analysis['required_tools'] ?? []
                ]);
                $this->em->flush();
                return;
            }

            $this->logger->info('Missing tools identified', [
                'task_id' => $task->getId(),
                'missing' => $missingTools
            ]);

            // Schritt 3: Generiere Code für fehlende Tools
            $generatedCode = $this->generateToolCode($missingTools, $analysis);

            // Schritt 4: Teste in Sandbox
            $testResults = $this->testInSandbox($generatedCode);

            // Schritt 5: Speichere für Review
            $task->setGeneratedFiles($generatedCode['files']);
            $task->setTestResults($testResults);

            if ($autoApprove && $testResults['passed']) {
                // Auto-Approve: Direkt implementieren
                $this->implementGeneratedCode($task);
                $task->setStatus('completed');
            } else {
                // Manuelles Review erforderlich
                $task->setStatus('awaiting_review');
            }

            $this->em->flush();

        } catch (\Exception $e) {
            $this->logger->error('Workflow execution failed', [
                'task_id' => $task->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $task->setStatus('failed');
            $this->em->flush();
            throw $e;
        }
    }

    /**
     * Analysiere User-Intent mit Gemini LLM
     */
    private function analyzeIntentWithLLM(string $prompt): array
    {
        $systemPrompt = <<<PROMPT
        Du bist ein AI-Agent-Architekt. Analysiere den User-Request und bestimme:

        1. **Intent**: Was will der User erreichen?
        2. **Required Services**: Welche Services werden benötigt?
        3. **Required Tools**: Welche konkreten Tools/Methoden?
        4. **External APIs**: Werden externe APIs benötigt?
        5. **Data Flow**: Wie fließen die Daten?

        Beispiel User-Request: "Vereinbare einen Termin mit klaus@mueller.de für Freitag 8-10 Uhr"

        Erwartete Antwort (JSON):
        {
            "intent": "schedule_meeting",
            "required_services": [
                {
                    "name": "EmailService",
                    "purpose": "Sende Termin-Einladung per Email",
                    "methods": ["sendMeetingInvitation"]
                },
                {
                    "name": "CalendarService", 
                    "purpose": "Prüfe Verfügbarkeit und erstelle Termin",
                    "methods": ["checkAvailability", "createEvent"]
                }
            ],
            "required_tools": [
                "email_sender",
                "calendar_manager"
            ],
            "external_apis": [
                {
                    "name": "smtp",
                    "purpose": "Email-Versand",
                    "credentials_required": true
                },
                {
                    "name": "google_calendar_api",
                    "purpose": "Kalender-Integration",
                    "credentials_required": true
                }
            ],
            "data_flow": [
                "1. Parse Email + Zeitfenster aus Prompt",
                "2. Prüfe Verfügbarkeit in Kalender",
                "3. Erstelle Termin-Vorschlag",
                "4. Sende Einladung per Email",
                "5. Warte auf Bestätigung"
            ],
            "security_concerns": [
                "Email-Adressen validieren",
                "SMTP-Credentials sicher speichern",
                "User-Approval vor Email-Versand"
            ]
        }

        Antworte NUR mit gültigem JSON, keine weiteren Erklärungen.
        PROMPT;

        try {
            $response = $this->callGeminiAPI([
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $systemPrompt],
                            ['text' => "User-Request: {$prompt}"]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 2048,
                ]
            ]);

            if (empty($response['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \RuntimeException('Empty response from Gemini');
            }

            $text = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // Extrahiere JSON aus Markdown-Code-Blöcken falls vorhanden
            if (preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
                $text = $matches[1];
            }

            $analysis = json_decode($text, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON from Gemini: ' . json_last_error_msg());
            }

            return $analysis;

        } catch (\Exception $e) {
            $this->logger->error('LLM Analysis failed', [
                'error' => $e->getMessage(),
                'prompt' => $prompt
            ]);
            throw $e;
        }
    }

    /**
     * Prüfe, welche Tools fehlen
     */
    private function identifyMissingTools(array $analysis): array
    {
        $missing = [];
        $servicesDir = $this->projectDir . '/src/Service';

        foreach ($analysis['required_services'] ?? [] as $service) {
            $serviceName = $service['name'];
            $serviceFile = $servicesDir . '/' . $serviceName . '.php';

            if (!file_exists($serviceFile)) {
                $missing[] = $service;
                $this->logger->info('Missing service detected', [
                    'service' => $serviceName,
                    'expected_path' => $serviceFile
                ]);
            }
        }

        return $missing;
    }

    /**
     * Generiere Tool-Code mit Gemini
     */
    private function generateToolCode(array $missingTools, array $analysis): array
    {
        $files = [];

        foreach ($missingTools as $tool) {
            $serviceName = $tool['name'];
            $methods = $tool['methods'] ?? [];
            $purpose = $tool['purpose'] ?? '';

            // 🔧 Vorherige Interpolation
            $methodList = implode(', ', $methods);
            $securityConcerns = implode("\n", $analysis['security_concerns'] ?? []);

            // 🧠 Heredoc mit interpolierten Variablen
            $codePrompt = <<<PROMPT
        Generiere einen vollständigen, produktionsreifen PHP-Service für Symfony 7.3.

        Service-Name: {$serviceName}
        Zweck: {$purpose}
        Benötigte Methoden: {$methodList}

        Anforderungen:
        1. **Namespace**: App\\Service
        2. **Security**: Input-Validierung, keine SQL-Injection
        3. **Error Handling**: Try-Catch mit sinnvollen Exceptions
        4. **Logging**: PSR-3 LoggerInterface injizieren
        5. **Type Safety**: Strict Types, vollständige Type-Hints
        6. **Documentation**: PHPDoc für alle Methoden
        7. **User Approval**: Vor kritischen Aktionen (Email, API-Calls) User-Bestätigung einfordern

        Security-Concerns:
        {$securityConcerns}

        Antworte NUR mit dem PHP-Code, keine Markdown-Blöcke, kein zusätzlicher Text.
        PROMPT;

            try {
                $response = $this->callGeminiAPI([
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $codePrompt]]]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 4096,
                    ]
                ]);

                $code = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Bereinige Code von Markdown
                $code = preg_replace('/```php\s*/s', '', $code);
                $code = preg_replace('/```\s*$/s', '', $code);
                $code = trim($code);

                $files[] = [
                    'path' => "src/Service/{$serviceName}.php",
                    'content' => $code,
                    'type' => 'service'
                ];

                // Generiere auch Test-Klasse
                $testCode = $this->generateTestClass($serviceName, $methods);
                $files[] = [
                    'path' => "tests/Service/{$serviceName}Test.php",
                    'content' => $testCode,
                    'type' => 'test'
                ];

            } catch (\Exception $e) {
                $this->logger->error('Code generation failed', [
                    'service' => $serviceName,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return ['files' => $files];
    }


    /**
     * Generiere Test-Klasse für Service
     */
    private function generateTestClass(string $serviceName, array $methods): string
    {
        $testPrompt = <<<PROMPT
            Generiere eine PHPUnit-Testklasse für {$serviceName}.

            Zu testende Methoden: {implode(', ', $methods)}

            Anforderungen:
            1. Namespace: App\\Tests\\Service
            2. Extends: PHPUnit\\Framework\\TestCase
            3. Mock alle Dependencies
            4. Teste Happy Path + Error Cases
            5. Vollständige Code-Coverage

            Antworte NUR mit dem PHP-Code.
            PROMPT;

        try {
            $response = $this->callGeminiAPI([
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $testPrompt]]]
                ]
            ]);

            $code = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $code = preg_replace('/```php\s*/s', '', $code);
            $code = preg_replace('/```\s*$/s', '', $code);
            
            return trim($code);

        } catch (\Exception $e) {
            $this->logger->warning('Test generation failed, using template', [
                'service' => $serviceName,
                'error' => $e->getMessage()
            ]);

            // Fallback: Minimaler Test-Template
            return $this->getTestTemplate($serviceName);
        }
    }

    /**
     * Teste generierten Code in Sandbox
     */
    private function testInSandbox(array $generatedCode): array
    {
        try {
            $response = $this->httpClient->request('POST', self::EXECUTOR_URL . '/execute', [
                'json' => [
                    'files' => $generatedCode['files'],
                    'run_tests' => true,
                    'timeout' => 30
                ],
                'timeout' => 35
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Sandbox returned non-200 status');
            }

            $result = $response->toArray();

            return [
                'passed' => $result['success'] ?? false,
                'output' => $result['output'] ?? '',
                'tests_run' => $result['tests_run'] ?? 0,
                'failures' => $result['failures'] ?? 0,
                'errors' => $result['errors'] ?? []
            ];

        } catch (\Exception $e) {
            $this->logger->error('Sandbox execution failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'passed' => false,
                'output' => $e->getMessage(),
                'tests_run' => 0,
                'failures' => 1,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Implementiere Code auf Host-System (NACH Approval)
     */
    public function implementGeneratedCode(AgentTask $task): array
    {
        $filesCreated = [];
        $projectRoot = $this->projectDir;

        foreach ($task->getGeneratedFiles() as $file) {
            $fullPath = $projectRoot . '/' . $file['path'];
            $directory = dirname($fullPath);

            // Verzeichnis erstellen
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Datei schreiben
            file_put_contents($fullPath, $file['content']);
            $filesCreated[] = $file['path'];

            $this->logger->info('File created', ['path' => $file['path']]);
        }

        // Post-Implementation Tasks
        $nextSteps = [
            'Service ist verfügbar unter: App\\Service\\' . basename($filesCreated[0], '.php')
        ];

        if ($this->hasEntities($task)) {
            $nextSteps[] = 'Führe Migrationen aus: php bin/console doctrine:migrations:diff';
            $nextSteps[] = 'Führe Migrationen aus: php bin/console doctrine:migrations:migrate';
        }

        return [
            'files_created' => $filesCreated,
            'next_steps' => $nextSteps
        ];
    }

    /**
     * Gemini API Call
     */
    private function callGeminiAPI(array $payload): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent';
        
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->geminiApiKey
                ],
                'json' => $payload,
                'timeout' => 60
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Gemini API returned status ' . $response->getStatusCode());
            }

            return $response->toArray();

        } catch (\Exception $e) {
            $this->logger->error('Gemini API call failed', [
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            throw $e;
        }
    }

    /**
     * Health-Check
     */
    public function checkExecutorHealth(): string
    {
        try {
            $response = $this->httpClient->request('GET', self::EXECUTOR_URL . '/health', [
                'timeout' => 3
            ]);

            return $response->getStatusCode() === 200 ? 'online' : 'degraded';
        } catch (\Exception) {
            return 'offline';
        }
    }

    private function hasEntities(AgentTask $task): bool
    {
        foreach ($task->getGeneratedFiles() as $file) {
            if ($file['type'] === 'entities') {
                return true;
            }
        }
        return false;
    }

    private function getTestTemplate(string $serviceName): string
{
    $namespace = "App\\Tests\\Service";
    $serviceNamespace = "App\\Service\\{$serviceName}";
    $testClassName = "{$serviceName}Test";

    $template = <<<PHP
    <?php
    namespace {$namespace};

    use {$serviceNamespace};
    use PHPUnit\\Framework\\TestCase;

    class {$testClassName} extends TestCase
    {
        public function testServiceExists(): void
        {
            \$service = new {$serviceName}();
            \$this->assertInstanceOf({$serviceName}::class, \$service);
        }
    }
    PHP;

    return $template;
}

}
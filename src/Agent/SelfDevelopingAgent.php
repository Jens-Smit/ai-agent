<?php
// src/Agent/SelfDevelopingAgent.php

namespace App\Agent;

use App\Entity\AgentTask;
use App\Repository\AgentTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Self-Developing AI Agent
 * 
 * Workflow:
 * 1. Analysiere User-Prompt (via Gemini)
 * 2. Prüfe vorhandene Tools
 * 3. Generiere fehlenden Code
 * 4. Teste in Executor-Sandbox
 * 5. Warte auf User-Approval
 * 6. Implementiere auf Host-System
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
        private readonly string $geminiApiKey
    ) {}

    /**
     * Haupteinstieg: Verarbeitet User-Prompt
     */
    public function processPrompt(string $prompt, bool $autoApprove = false, array $config = []): string
    {
        // Task erstellen
        $task = new AgentTask();
        $task->setId(uniqid('task_'));
        $task->setPrompt($prompt);
        $task->setStatus('processing');
        $task->setConfig($config);
        $task->setAutoApprove($autoApprove);
        $task->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($task);
        $this->em->flush();

        $this->logger->info('Agent Task created', [
            'task_id' => $task->getId(),
            'prompt' => $prompt
        ]);

        // Asynchrone Verarbeitung starten (via Messenger oder direkter Call)
        $this->executeAgentWorkflow($task);

        return $task->getId();
    }

    /**
     * Agent-Workflow: Tool-Analyse -> Code-Gen -> Test -> Review
     */
    private function executeAgentWorkflow(AgentTask $task): void
    {
        try {
            // Schritt 1: Tool-Analyse mit Gemini
            $analysis = $this->analyzePromptWithLLM($task->getPrompt());

            if ($analysis['tool_exists']) {
                // Tool existiert bereits
                $task->setStatus('completed');
                $task->setResult([
                    'message' => 'Tool bereits vorhanden',
                    'tool_name' => $analysis['tool_name']
                ]);
            } else {
                // Schritt 2: Code-Generierung
                $generatedCode = $this->generateCode($analysis);

                // Schritt 3: Code in Sandbox testen
                $testResults = $this->testInSandbox($generatedCode);

                // Schritt 4: Task in Review-Status setzen
                $task->setGeneratedFiles($generatedCode['files']);
                $task->setTestResults($testResults);

                if ($task->isAutoApprove() && $testResults['passed']) {
                    // Auto-Approve: Direkt implementieren
                    $this->implementGeneratedCode($task);
                    $task->setStatus('completed');
                } else {
                    // Manuelles Review erforderlich
                    $task->setStatus('awaiting_review');
                }
            }

            $this->em->flush();

        } catch (\Exception $e) {
            $this->logger->error('Agent workflow failed', [
                'task_id' => $task->getId(),
                'error' => $e->getMessage()
            ]);

            $task->setStatus('failed');
            $task->setError($e->getMessage());
            $this->em->flush();
        }
    }

    /**
     * LLM-Analyse: Welche Tools werden benötigt?
     */
    private function analyzePromptWithLLM(string $prompt): array
    {
        $systemPrompt = <<<PROMPT
Du bist ein Experte für Symfony-Architektur. Analysiere den User-Prompt und bestimme:
1. Welche Services/Tools werden benötigt?
2. Existieren diese bereits im System?
3. Falls nicht: Welche Klassen müssen erstellt werden?

Antworte im JSON-Format:
{
    "tool_exists": false,
    "required_tools": ["WeatherService"],
    "file_structure": {
        "services": ["src/Service/WeatherService.php"],
        "entities": ["src/Entity/WeatherCache.php"],
        "tests": ["tests/Service/WeatherServiceTest.php"]
    }
}
PROMPT;

        $response = $this->callGeminiAPI([
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => "User-Anfrage: {$prompt}"]
                    ]
                ]
            ]
        ]);

        return json_decode($response['candidates'][0]['content']['parts'][0]['text'], true);
    }

    /**
     * Code-Generierung mit Gemini
     */
    private function generateCode(array $analysis): array
    {
        $files = [];

        foreach ($analysis['file_structure'] as $type => $paths) {
            foreach ($paths as $path) {
                $prompt = "Generiere vollständigen, produktionsreifen PHP-Code für: {$path}";
                $code = $this->callGeminiAPI([
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $prompt]]]
                    ]
                ]);

                $files[] = [
                    'path' => $path,
                    'content' => $code['candidates'][0]['content']['parts'][0]['text'],
                    'type' => $type
                ];
            }
        }

        return ['files' => $files];
    }

    /**
     * Code in Executor-Sandbox testen
     */
    private function testInSandbox(array $generatedCode): array
    {
        try {
            $response = $this->httpClient->request('POST', self::EXECUTOR_URL . '/execute', [
                'json' => [
                    'files' => $generatedCode['files'],
                    'run_tests' => true
                ],
                'timeout' => 30
            ]);

            $result = $response->toArray();

            return [
                'passed' => $result['success'] ?? false,
                'output' => $result['output'] ?? '',
                'tests_run' => $result['tests_run'] ?? 0,
                'failures' => $result['failures'] ?? 0
            ];

        } catch (\Exception $e) {
            $this->logger->error('Sandbox execution failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'passed' => false,
                'output' => $e->getMessage(),
                'tests_run' => 0,
                'failures' => 1
            ];
        }
    }

    /**
     * Code auf Host-System implementieren (NACH Approval)
     */
    public function implementGeneratedCode(AgentTask $task): array
    {
        $filesCreated = [];
        $projectRoot = $this->getProjectRoot();

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
        $nextSteps = [];
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
     * Executor Health-Check
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

    /**
     * Gemini API Call
     */
    private function callGeminiAPI(array $payload): array
    {
        $client = HttpClient::create();

        $response = $client->request('POST', 'https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->geminiApiKey
            ],
            'json' => $payload
        ]);

        return $response->toArray();
    }

    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
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
}
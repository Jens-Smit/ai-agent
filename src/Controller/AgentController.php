<?php
namespace App\Controller;

use App\Agent\SelfDevelopingAgent;
use App\Repository\AgentTaskRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agent', name: 'api_agent_')]
class AgentController extends AbstractController
{
    public function __construct(
        private readonly SelfDevelopingAgent $agent,
        private readonly AgentTaskRepository $taskRepository
    ) {}

    /**
     * Agent-Prompt Endpoint - Startet selbst-entwickelnden Workflow
     */
    #[Route('/prompt', name: 'prompt', methods: ['POST'])]
    #[OA\Post(
        path: '/api/agent/prompt',
        summary: 'Starte selbst-entwickelnden AI-Agenten',
        description: 'Der Agent analysiert den Prompt, identifiziert fehlende Tools und generiert Code',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['prompt'],
                properties: [
                    new OA\Property(
                        property: 'prompt',
                        type: 'string',
                        example: 'Vereinbare einen Termin mit klaus@mueller.de für Freitag 8-10 Uhr'
                    ),
                    new OA\Property(
                        property: 'auto_approve',
                        type: 'boolean',
                        example: false,
                        description: 'Automatische Freigabe (NICHT für Production!)'
                    ),
                    new OA\Property(
                        property: 'config',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'test_mode', type: 'boolean'),
                            new OA\Property(property: 'max_iterations', type: 'integer'),
                            new OA\Property(property: 'require_confirmation', type: 'boolean', description: 'User-Bestätigung vor kritischen Aktionen')
                        ]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Task erstellt, Agent analysiert',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'processing'),
                        new OA\Property(property: 'task_id', type: 'string', example: 'task_abc123'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(
                            property: 'endpoints',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'approve', type: 'string'),
                                new OA\Property(property: 'reject', type: 'string')
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Ungültiger Prompt'),
            new OA\Response(response: 429, description: 'Rate Limit überschritten')
        ]
    )]
    public function prompt(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validierung
        if (!isset($data['prompt']) || empty($data['prompt'])) {
            return new JsonResponse([
                'error' => 'Prompt ist erforderlich'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Prompt-Länge limitieren
        if (strlen($data['prompt']) > 2000) {
            return new JsonResponse([
                'error' => 'Prompt zu lang (Max: 2000 Zeichen)'
            ], Response::HTTP_BAD_REQUEST);
        }

        $autoApprove = $data['auto_approve'] ?? false;
        $config = $data['config'] ?? [];

        // Sicherheitswarnung bei auto_approve
        if ($autoApprove) {
            return new JsonResponse([
                'error' => 'auto_approve ist aus Sicherheitsgründen deaktiviert',
                'message' => 'Alle Code-Änderungen müssen manuell freigegeben werden',
                'hint' => 'Setze auto_approve: false und nutze /approve Endpoint'
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            // Task erstellen und Agent starten
            $taskId = $this->agent->processPrompt(
                prompt: $data['prompt'],
                autoApprove: false, // Immer false für Sicherheit
                config: $config
            );

            return new JsonResponse([
                'status' => 'processing',
                'task_id' => $taskId,
                'message' => 'Agent analysiert die Anfrage und identifiziert benötigte Tools...',
                'estimated_time' => '30-60 Sekunden',
                'endpoints' => [
                    'status' => "/api/agent/task/{$taskId}",
                    'approve' => "/api/agent/task/{$taskId}/approve",
                    'reject' => "/api/agent/task/{$taskId}/reject"
                ],
                'next_steps' => [
                    '1. Polling: GET /api/agent/task/' . $taskId,
                    '2. Review: Prüfe generierte Dateien',
                    '3. Approval: POST /api/agent/task/' . $taskId . '/approve'
                ]
            ], Response::HTTP_ACCEPTED);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Agent-Verarbeitung fehlgeschlagen',
                'message' => $e->getMessage(),
                'task_id' => $taskId ?? null
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Task-Status abrufen (mit detaillierten Informationen)
     */
    #[Route('/task/{taskId}', name: 'task_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/agent/task/{taskId}',
        summary: 'Task-Status abrufen',
        tags: ['AI Agent'],
        parameters: [
            new OA\Parameter(
                name: 'taskId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Task-Details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'task_id', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', enum: ['processing', 'awaiting_review', 'completed', 'failed']),
                        new OA\Property(property: 'created_at', type: 'string'),
                        new OA\Property(property: 'analysis', type: 'object'),
                        new OA\Property(property: 'generated_files', type: 'array'),
                        new OA\Property(property: 'test_results', type: 'object'),
                        new OA\Property(property: 'security_review', type: 'object')
                    ]
                )
            )
        ]
    )]
    public function getTaskStatus(string $taskId): JsonResponse
    {
        $task = $this->taskRepository->find($taskId);

        if (!$task) {
            return new JsonResponse([
                'error' => 'Task nicht gefunden'
            ], Response::HTTP_NOT_FOUND);
        }

        $response = [
            'task_id' => $task->getId(),
            'status' => $task->getStatus(),
            'created_at' => $task->getCreatedAt()->format('c'),
            'updated_at' => $task->getUpdatedAt()?->format('c')
        ];

        // Zusätzliche Infos je nach Status
        if ($task->getStatus() === 'awaiting_review') {
            $response['generated_files'] = $this->formatGeneratedFiles($task->getGeneratedFiles());
            $response['test_results'] = $task->getTestResults();
            $response['security_review'] = $this->performSecurityReview($task->getGeneratedFiles());
            $response['actions'] = $this->getAvailableActions($task);
            
            // Warnung bei kritischen Änderungen
            if ($this->hasCriticalChanges($task->getGeneratedFiles())) {
                $response['warnings'] = [
                    'critical_changes_detected' => true,
                    'message' => 'Dieser Code enthält kritische Funktionen (Email, API-Calls, DB-Zugriff)',
                    'recommendation' => 'Prüfe den Code sorgfältig vor der Freigabe'
                ];
            }
        }

        if ($task->getStatus() === 'failed') {
            $response['error'] = $task->getError() ?? 'Unbekannter Fehler';
        }

        if ($task->getStatus() === 'completed') {
            $response['result'] = $task->getResult();
        }

        return new JsonResponse($response);
    }

    /**
     * Task-Approval (mit Sicherheitschecks)
     */
    #[Route('/task/{taskId}/approve', name: 'task_approve', methods: ['POST'])]
    #[OA\Post(
        path: '/api/agent/task/{taskId}/approve',
        summary: 'Code-Freigabe erteilen',
        description: 'Gibt generierten Code nach Review frei und implementiert ihn',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approved', type: 'boolean', example: true),
                    new OA\Property(property: 'comment', type: 'string'),
                    new OA\Property(property: 'security_confirmed', type: 'boolean', description: 'Bestätigung der Security-Review')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Code implementiert',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'files_created', type: 'array'),
                        new OA\Property(property: 'next_steps', type: 'array')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Task nicht im Review-Status')
        ]
    )]
    public function approveTask(string $taskId, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($taskId);

        if (!$task) {
            return new JsonResponse(['error' => 'Task nicht gefunden'], 404);
        }

        if ($task->getStatus() !== 'awaiting_review') {
            return new JsonResponse([
                'error' => 'Task ist nicht im Review-Status',
                'current_status' => $task->getStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $approved = $data['approved'] ?? true;
        $securityConfirmed = $data['security_confirmed'] ?? false;

        // Bei kritischen Änderungen Security-Bestätigung erfordern
        if ($this->hasCriticalChanges($task->getGeneratedFiles()) && !$securityConfirmed) {
            return new JsonResponse([
                'error' => 'Security-Bestätigung erforderlich',
                'message' => 'Dieser Code enthält kritische Funktionen',
                'required_field' => 'security_confirmed: true',
                'critical_functions' => $this->identifyCriticalFunctions($task->getGeneratedFiles())
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            if ($approved) {
                // Führe finale Security-Checks durch
                $securityIssues = $this->performDeepSecurityScan($task->getGeneratedFiles());
                
                if (!empty($securityIssues)) {
                    return new JsonResponse([
                        'error' => 'Security-Probleme gefunden',
                        'issues' => $securityIssues,
                        'recommendation' => 'Behebe die Probleme oder lehne den Task ab'
                    ], Response::HTTP_FORBIDDEN);
                }

                // Code auf Host-System implementieren
                $result = $this->agent->implementGeneratedCode($task);

                $task->setStatus('completed');
                $task->setResult($result);
                $this->taskRepository->save($task);

                return new JsonResponse([
                    'status' => 'completed',
                    'message' => 'Code wurde erfolgreich implementiert',
                    'files_created' => $result['files_created'],
                    'next_steps' => $result['next_steps'],
                    'deployed_at' => date('c')
                ]);
            } else {
                $task->setStatus('rejected');
                $this->taskRepository->save($task);

                return new JsonResponse([
                    'status' => 'rejected',
                    'message' => 'Task wurde abgelehnt',
                    'comment' => $data['comment'] ?? null
                ]);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Implementierung fehlgeschlagen',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Task ablehnen
     */
    #[Route('/task/{taskId}/reject', name: 'task_reject', methods: ['POST'])]
    public function rejectTask(string $taskId, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($taskId);

        if (!$task) {
            return new JsonResponse(['error' => 'Task nicht gefunden'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        $task->setStatus('rejected');
        $task->setResult([
            'rejected_by' => 'user',
            'reason' => $data['reason'] ?? 'Keine Begründung angegeben',
            'rejected_at' => date('c')
        ]);
        $this->taskRepository->save($task);

        return new JsonResponse([
            'status' => 'rejected',
            'message' => 'Task wurde abgelehnt und verworfen'
        ]);
    }

    /**
     * Health-Check für Agent-System
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
            'agent' => 'online',
            'executor' => $this->agent->checkExecutorHealth(),
            'version' => '2.0.0',
            'features' => [
                'self_developing' => true,
                'code_generation' => true,
                'sandbox_testing' => true,
                'security_review' => true,
                'user_approval_required' => true
            ],
            'timestamp' => date('c')
        ]);
    }

    // === Private Helper-Methoden ===

    private function formatGeneratedFiles(array $files): array
    {
        return array_map(function($file) {
            return [
                'path' => $file['path'],
                'type' => $file['type'],
                'size' => strlen($file['content']),
                'preview' => substr($file['content'], 0, 200) . '...',
                'functions' => $this->extractFunctionNames($file['content'])
            ];
        }, $files);
    }

    private function performSecurityReview(array $files): array
    {
        $review = [
            'status' => 'pending',
            'checks' => []
        ];

        foreach ($files as $file) {
            $content = $file['content'];
            
            $review['checks'][] = [
                'file' => $file['path'],
                'sql_injection_risk' => $this->checkSQLInjection($content),
                'xss_risk' => $this->checkXSS($content),
                'file_access_risk' => $this->checkFileAccess($content),
                'network_access' => $this->checkNetworkAccess($content),
                'sensitive_data' => $this->checkSensitiveData($content)
            ];
        }

        return $review;
    }

    private function hasCriticalChanges(array $files): bool
    {
        foreach ($files as $file) {
            $content = $file['content'];
            
            // Prüfe auf kritische Funktionen
            if (preg_match('/mail\(|wp_mail|PHPMailer|Swift_Mailer/i', $content)) {
                return true;
            }
            if (preg_match('/exec\(|shell_exec|system\(|passthru/i', $content)) {
                return true;
            }
            if (preg_match('/file_get_contents.*http|curl_exec|HttpClient/i', $content)) {
                return true;
            }
        }

        return false;
    }

    private function identifyCriticalFunctions(array $files): array
    {
        $critical = [];

        foreach ($files as $file) {
            if (preg_match_all('/(mail|exec|curl_exec|file_get_contents)\s*\(/i', $file['content'], $matches)) {
                $critical[$file['path']] = array_unique($matches[0]);
            }
        }

        return $critical;
    }

    private function performDeepSecurityScan(array $files): array
    {
        $issues = [];

        foreach ($files as $file) {
            $content = $file['content'];
            
            // SQL Injection Check
            if (preg_match('/\$.*\s*=.*\$_(GET|POST|REQUEST)/i', $content)) {
                $issues[] = [
                    'severity' => 'high',
                    'type' => 'sql_injection_risk',
                    'file' => $file['path'],
                    'message' => 'Potentielle SQL-Injection durch unvalidierte User-Eingabe'
                ];
            }

            // Hardcoded Credentials
            if (preg_match('/(password|secret|api_key)\s*=\s*["\'](?!env\()/i', $content)) {
                $issues[] = [
                    'severity' => 'critical',
                    'type' => 'hardcoded_credentials',
                    'file' => $file['path'],
                    'message' => 'Hardcoded Credentials gefunden'
                ];
            }
        }

        return $issues;
    }

    private function checkSQLInjection(string $content): string
    {
        if (preg_match('/\$.*query.*\$_(GET|POST)/i', $content)) {
            return 'high_risk';
        }
        return 'safe';
    }

    private function checkXSS(string $content): string
    {
        if (preg_match('/echo.*\$_(GET|POST)/i', $content)) {
            return 'high_risk';
        }
        return 'safe';
    }

    private function checkFileAccess(string $content): string
    {
        if (preg_match('/file_(get|put)_contents|fopen|unlink/i', $content)) {
            return 'medium_risk';
        }
        return 'safe';
    }

    private function checkNetworkAccess(string $content): string
    {
        if (preg_match('/curl_exec|file_get_contents.*http|HttpClient/i', $content)) {
            return 'medium_risk';
        }
        return 'safe';
    }

    private function checkSensitiveData(string $content): string
    {
        if (preg_match('/(password|api_key|secret)\s*=/i', $content)) {
            return 'review_required';
        }
        return 'safe';
    }

    private function extractFunctionNames(string $content): array
    {
        preg_match_all('/public function (\w+)\(/i', $content, $matches);
        return $matches[1] ?? [];
    }

    private function getAvailableActions($task): array
    {
        $actions = [];

        if ($task->getStatus() === 'awaiting_review') {
            $actions['approve'] = "POST /api/agent/task/{$task->getId()}/approve";
            $actions['reject'] = "POST /api/agent/task/{$task->getId()}/reject";
        }

        return $actions;
    }
}
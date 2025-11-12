<?php

namespace App\Controller;

use App\DTO\AgentPromptRequest;
use App\Service\AgentStatusService;
use OpenApi\Attributes as OA;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use App\Tool\DeployGeneratedCodeTool; // Import the DeployGeneratedCodeTool

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpClient\Exception\ServerException; // Consider this or similar for API errors


class AiAgentController extends AbstractController
{
    

    public function __construct(
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService, // Inject the new service
        private ValidatorInterface $validator
    ) {
    }
   

    #[Route('/api/devAgent', name: 'api_devAgent_v2', methods: ['POST'])]
    #[OA\Post(
        path: '/api/devAgent',
        summary: 'Symfony Developing expert AI Agent',
        description: 'Sends a prompt to the AI agent to create ne featchers to the system.',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                // Assuming AgentPromptRequest DTO defines the structure
                ref: '#/components/schemas/AgentPromptRequest'
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File generation or agent execution successful. Deployment script provided if files were created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success | completed'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'file_path', type: 'string', nullable: true),
                        new OA\Property(property: 'details', type: 'string', nullable: true),
                        new OA\Property(property: 'ai_response', type: 'string'),
                        new OA\Property(property: 'files_created', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                        new OA\Property(property: 'deployment_instructions', type: 'string', nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Bad request, e.g., missing prompt or validation errors.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'violations', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Internal Server Error during agent execution or deployment.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'details', type: 'string', nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(
                response: 502,
                description: 'AI Agent returned no usable content or became unavailable after retries.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'raw_result', type: 'object', nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            )
        ]
    )]
    public function generateFile(
        Request $request,
        #[Autowire(service: 'ai.agent.file_generator')]
        AgentInterface $agent,
        DeployGeneratedCodeTool $deployTool // Inject the DeployGeneratedCodeTool
    ): JsonResponse {
        $this->agentStatusService->clearStatuses(); // Clear previous statuses
        $this->agentStatusService->addStatus('API-Aufruf erhalten, Dateigenerierung gestartet.');

        $data = json_decode($request->getContent(), true);

        // Use DTO for validation
        $agentPromptRequest = new AgentPromptRequest();
        $agentPromptRequest->prompt = $data['prompt'] ?? null;

        $violations = $this->validator->validate($agentPromptRequest);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
            $this->agentStatusService->addStatus('Fehler: Validierung des Prompts fehlgeschlagen.');
            $this->logger->warning('Invalid prompt provided.', ['violations' => $errors]);
            return $this->json([
                'error' => 'Invalid prompt provided.',
                'violations' => $errors,
                'statuses' => $this->agentStatusService->getStatuses()
            ], 400);
        }

        $userPrompt = $agentPromptRequest->prompt;

        $maxRetries = 50;
        $retryDelay = 60; // seconds
        $lastException = null;
        $result = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->logger->info(sprintf('Starting AI agent call (Attempt %d/%d)', $attempt, $maxRetries), ['prompt' => $userPrompt]);
                $this->agentStatusService->addStatus(sprintf('Prompt an AI-Agent gesendet (Versuch %d/%d).', $attempt, $maxRetries));

                $messages = new MessageBag(
                    Message::ofUser($userPrompt)
                );

                $result = $agent->call($messages);
                $this->agentStatusService->addStatus('Antwort vom AI-Agent erhalten.');
                $this->logger->info('AI agent call successful.', ['attempt' => $attempt]);
                break; // Exit loop on success
            } catch (ServerException $e) { // Catch specific API errors if possible
                $lastException = $e;
                $this->logger->error(sprintf('AI agent call failed (Attempt %d/%d): %s', $attempt, $maxRetries, $e->getMessage()), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Fehler beim AI-Agent-Aufruf (Versuch %d/%d): %s', $attempt, $maxRetries, $e->getMessage()));

                if ($attempt < $maxRetries) {
                    $this->logger->warning(sprintf('Retrying AI agent call in %d seconds...', $retryDelay));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', $retryDelay));
                    sleep($retryDelay);
                }
            } catch (\Throwable $e) { // Catch all throwables (Error and Exception)
                $lastException = $e;
                $this->logger->error(sprintf('An unexpected error (Throwable) occurred during AI agent call (Attempt %d/%d): %s', $attempt, $maxRetries, $e->getMessage()), [
                    'exception_class' => get_class($e), // Log the actual class of the exception
                    'exception_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Unerwarteter Fehler beim AI-Agent-Aufruf (Versuch %d/%d): %s', $attempt, $maxRetries, $e->getMessage()));
                if ($attempt < $maxRetries) {
                    $this->logger->warning(sprintf('Retrying AI agent call in %d seconds...', $retryDelay));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', $retryDelay));
                    sleep($retryDelay);
                }
            }
        }

        if ($result === null) {
            $this->logger->critical('All AI agent call attempts failed after retries.', ['last_exception' => $lastException ? $lastException->getMessage() : 'N/A']);
            $this->agentStatusService->addStatus('Kritischer Fehler: AI-Agent nach mehreren Versuchen nicht verfügbar.');
            return $this->json([
                'error' => 'AI Agent is currently unavailable after multiple retries. Please try again later.',
                'details' => $lastException ? $lastException->getMessage() : 'No specific error message available.',
                'statuses' => $this->agentStatusService->getStatuses()
            ], 503); // Service Unavailable
        }

        try {
            $aiContent = null;
            if (method_exists($result, 'getContent')) {
                try {
                    $aiContent = $result->getContent();
                } catch (\Throwable $e) {
                    $this->logger->warning('getContent() warf eine Ausnahme', ['exception' => $e->getMessage()]);
                    $this->agentStatusService->addStatus('Warnung: Fehler beim Extrahieren des Agenten-Inhalts.');
                }
            }

            if (empty($aiContent)) {
                $raw = null;
                try {
                    $raw = json_decode(json_encode($result), true);
                } catch (\Throwable $e) {
                    $raw = (string) $result;
                }

                $this->logger->error('Agent hat keinen verwertbaren Text zurückgegeben', ['raw_result' => $raw]);
                $this->agentStatusService->addStatus('Fehler: Agent hat keinen verwertbaren Textinhalt zurückgegeben.');

                return $this->json([
                    'status' => 'no_content',
                    'message' => 'Agent returned no textual content. See server logs (raw_result) for payload snapshot.',
                    'raw_result' => $raw,
                    'statuses' => $this->agentStatusService->getStatuses()
                ], 502);
            }

            $this->agentStatusService->addStatus('Überprüfung auf erstellte Dateien.');
            $generatedCodeDir = __DIR__ . '/../../generated_code/';
            $recentFiles = [];
            if (is_dir($generatedCodeDir)) {
                $files = scandir($generatedCodeDir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $filepath = $generatedCodeDir . $file;
                    // Consider files created/modified in the last 60 seconds
                    if (filemtime($filepath) > time() - 60) {
                        $recentFiles[] = $file;
                    }
                }
            }

            if (!empty($recentFiles)) {
                $this->agentStatusService->addStatus(sprintf('Datei(en) erstellt: %s. Bereite Bereitstellung vor.', implode(', ', $recentFiles)));
                $this->logger->info('Files created by AI agent.', ['files' => $recentFiles]);

                $filesToDeploy = [];
                foreach ($recentFiles as $file) {
                    $targetPath = '';
                    if (str_ends_with($file, '.php')) {
                        if (str_ends_with($file, 'Test.php')) {
                             $targetPath = 'tests/'. $file;
                        } else {
                            $targetPath = 'src/'. $file; // Simple heuristic, refine if needed
                        }
                    } elseif (str_ends_with($file, '.yaml') || str_ends_with($file, '.json')) {
                        $targetPath = 'config/'. $file;
                    } elseif (preg_match('/^Version\d{14}\.php$/', $file)) { // migration files
                        $targetPath = 'migrations/' . $file;
                    }
                    else {
                        $targetPath = 'generated_code/'. $file; // Default for other file types
                    }
                    $filesToDeploy[] = ['source_file' => $file, 'target_path' => $targetPath];
                }

                // Call the DeployGeneratedCodeTool
                $deploymentResult = $deployTool->__invoke($filesToDeploy);
                $this->agentStatusService->addStatus('Bereitstellungsskript-Generierung abgeschlossen.');
                $this->logger->info('Deployment script generation completed.', ['deployment_result_summary' => substr($deploymentResult, 0, 200)]);

                return $this->json([
                    'status' => 'success',
                    'message' => 'File generation with RAG successful. Deployment script generated.',
                    'ai_response' => $aiContent,
                    'files_created' => $recentFiles,
                    'deployment_instructions' => $deploymentResult,
                    'statuses' => $this->agentStatusService->getStatuses()
                ]);

            }

            $this->logger->info('Agent execution completed, no new files found.', ['response' => $aiContent]);
            $this->agentStatusService->addStatus('Keine neuen Dateien gefunden, Agent hat möglicherweise nur Informationen bereitgestellt.');
            return $this->json([
                'status' => 'completed',
                'message' => 'Agent completed execution.',
                'ai_response' => $aiContent,
                'hint' => 'Check if the agent decided to create a file or just provide information.',
                'statuses' => $this->agentStatusService->getStatuses()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Agent execution failed during post-processing or deployment.', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->agentStatusService->addStatus(sprintf('Kritischer Fehler bei der Agenten-Nachbearbeitung oder Bereitstellung: %s', $e->getMessage()));

            return $this->json([
                'error' => 'Agent execution failed during post-processing or deployment.',
                'details' => $e->getMessage(),
                'statuses' => $this->agentStatusService->getStatuses()
            ], 500);
        }
    }
#[Route('/api/frondend_devAgent', name: 'api_frondend_devAgent', methods: ['POST'])]
    #[OA\Post(
        path: '/api/frondend_devAgent',
        summary: 'React nativ Developing expert AI Agent',
        description: 'Sends a prompt to the AI agent to create ne featchers to the system.',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                // Assuming AgentPromptRequest DTO defines the structure
                ref: '#/components/schemas/AgentPromptRequest'
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'File generation or agent execution successful. Deployment script provided if files were created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success | completed'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'file_path', type: 'string', nullable: true),
                        new OA\Property(property: 'details', type: 'string', nullable: true),
                        new OA\Property(property: 'ai_response', type: 'string'),
                        new OA\Property(property: 'files_created', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                        new OA\Property(property: 'deployment_instructions', type: 'string', nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Bad request, e.g., missing prompt or validation errors.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'violations', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Internal Server Error during agent execution or deployment.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'details', type: 'string', nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            ),
            new OA\Response(
                response: 502,
                description: 'AI Agent returned no usable content or became unavailable after retries.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'raw_result', type: 'object', nullable: true),
                        new OA\Property(property: 'statuses', type: 'array', items: new OA\Items(type: 'object'))
                    ]
                )
            )
        ]
    )]
    public function generateFrondend(
        Request $request,
        #[Autowire(service: 'ai.agent.frontend_generator')]
        AgentInterface $agent,
        DeployGeneratedCodeTool $deployTool // Inject the DeployGeneratedCodeTool
    ): JsonResponse {
        $this->agentStatusService->clearStatuses(); // Clear previous statuses
        $this->agentStatusService->addStatus('API-Aufruf erhalten, Dateigenerierung gestartet.');

        $data = json_decode($request->getContent(), true);

        // Use DTO for validation
        $agentPromptRequest = new AgentPromptRequest();
        $agentPromptRequest->prompt = $data['prompt'] ?? null;

        $violations = $this->validator->validate($agentPromptRequest);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
            $this->agentStatusService->addStatus('Fehler: Validierung des Prompts fehlgeschlagen.');
            $this->logger->warning('Invalid prompt provided.', ['violations' => $errors]);
            return $this->json([
                'error' => 'Invalid prompt provided.',
                'violations' => $errors,
                'statuses' => $this->agentStatusService->getStatuses()
            ], 400);
        }

        $userPrompt = $agentPromptRequest->prompt;

        $maxRetries = 50;
        $retryDelay = 60; // seconds
        $lastException = null;
        $result = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->logger->info(sprintf('Starting AI agent call (Attempt %d/%d)', $attempt, $maxRetries), ['prompt' => $userPrompt]);
                $this->agentStatusService->addStatus(sprintf('Prompt an AI-Agent gesendet (Versuch %d/%d).', $attempt, $maxRetries));

                $messages = new MessageBag(
                    Message::ofUser($userPrompt)
                );

                $result = $agent->call($messages);
                $this->agentStatusService->addStatus('Antwort vom AI-Agent erhalten.');
                $this->logger->info('AI agent call successful.', ['attempt' => $attempt]);
                break; // Exit loop on success
            } catch (ServerException $e) { // Catch specific API errors if possible
                $lastException = $e;
                $this->logger->error(sprintf('AI agent call failed (Attempt %d/%d): %s', $attempt, $maxRetries, $e->getMessage()), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Fehler beim AI-Agent-Aufruf (Versuch %d/%d): %s', $attempt, $maxRetries, $e->getMessage()));

                if ($attempt < $maxRetries) {
                    $this->logger->warning(sprintf('Retrying AI agent call in %d seconds...', $retryDelay));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', $retryDelay));
                    sleep($retryDelay);
                }
            } catch (\Throwable $e) { // Catch all throwables (Error and Exception)
                $lastException = $e;
                $this->logger->error(sprintf('An unexpected error (Throwable) occurred during AI agent call (Attempt %d/%d): %s', $attempt, $maxRetries, $e->getMessage()), [
                    'exception_class' => get_class($e), // Log the actual class of the exception
                    'exception_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Unerwarteter Fehler beim AI-Agent-Aufruf (Versuch %d/%d): %s', $attempt, $maxRetries, $e->getMessage()));
                if ($attempt < $maxRetries) {
                    $this->logger->warning(sprintf('Retrying AI agent call in %d seconds...', $retryDelay));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', $retryDelay));
                    sleep($retryDelay);
                }
            }
        }

        if ($result === null) {
            $this->logger->critical('All AI agent call attempts failed after retries.', ['last_exception' => $lastException ? $lastException->getMessage() : 'N/A']);
            $this->agentStatusService->addStatus('Kritischer Fehler: AI-Agent nach mehreren Versuchen nicht verfügbar.');
            return $this->json([
                'error' => 'AI Agent is currently unavailable after multiple retries. Please try again later.',
                'details' => $lastException ? $lastException->getMessage() : 'No specific error message available.',
                'statuses' => $this->agentStatusService->getStatuses()
            ], 503); // Service Unavailable
        }

        try {
            $aiContent = null;
            if (method_exists($result, 'getContent')) {
                try {
                    $aiContent = $result->getContent();
                } catch (\Throwable $e) {
                    $this->logger->warning('getContent() warf eine Ausnahme', ['exception' => $e->getMessage()]);
                    $this->agentStatusService->addStatus('Warnung: Fehler beim Extrahieren des Agenten-Inhalts.');
                }
            }

            if (empty($aiContent)) {
                $raw = null;
                try {
                    $raw = json_decode(json_encode($result), true);
                } catch (\Throwable $e) {
                    $raw = (string) $result;
                }

                $this->logger->error('Agent hat keinen verwertbaren Text zurückgegeben', ['raw_result' => $raw]);
                $this->agentStatusService->addStatus('Fehler: Agent hat keinen verwertbaren Textinhalt zurückgegeben.');

                return $this->json([
                    'status' => 'no_content',
                    'message' => 'Agent returned no textual content. See server logs (raw_result) for payload snapshot.',
                    'raw_result' => $raw,
                    'statuses' => $this->agentStatusService->getStatuses()
                ], 502);
            }

            $this->agentStatusService->addStatus('Überprüfung auf erstellte Dateien.');
            $generatedCodeDir = __DIR__ . '/../../generated_code/';
            $recentFiles = [];
            if (is_dir($generatedCodeDir)) {
                $files = scandir($generatedCodeDir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $filepath = $generatedCodeDir . $file;
                    // Consider files created/modified in the last 60 seconds
                    if (filemtime($filepath) > time() - 60) {
                        $recentFiles[] = $file;
                    }
                }
            }

            if (!empty($recentFiles)) {
                $this->agentStatusService->addStatus(sprintf('Datei(en) erstellt: %s. Bereite Bereitstellung vor.', implode(', ', $recentFiles)));
                $this->logger->info('Files created by AI agent.', ['files' => $recentFiles]);

                $filesToDeploy = [];
                foreach ($recentFiles as $file) {
                    $targetPath = '';
                    if (str_ends_with($file, '.php')) {
                        if (str_ends_with($file, 'Test.php')) {
                             $targetPath = 'tests/'. $file;
                        } else {
                            $targetPath = 'src/'. $file; // Simple heuristic, refine if needed
                        }
                    } elseif (str_ends_with($file, '.yaml') || str_ends_with($file, '.json')) {
                        $targetPath = 'config/'. $file;
                    } elseif (preg_match('/^Version\d{14}\.php$/', $file)) { // migration files
                        $targetPath = 'migrations/' . $file;
                    }
                    else {
                        $targetPath = 'generated_code/'. $file; // Default for other file types
                    }
                    $filesToDeploy[] = ['source_file' => $file, 'target_path' => $targetPath];
                }

                // Call the DeployGeneratedCodeTool
                $deploymentResult = $deployTool->__invoke($filesToDeploy);
                $this->agentStatusService->addStatus('Bereitstellungsskript-Generierung abgeschlossen.');
                $this->logger->info('Deployment script generation completed.', ['deployment_result_summary' => substr($deploymentResult, 0, 200)]);

                return $this->json([
                    'status' => 'success',
                    'message' => 'File generation with RAG successful. Deployment script generated.',
                    'ai_response' => $aiContent,
                    'files_created' => $recentFiles,
                    'deployment_instructions' => $deploymentResult,
                    'statuses' => $this->agentStatusService->getStatuses()
                ]);

            }

            $this->logger->info('Agent execution completed, no new files found.', ['response' => $aiContent]);
            $this->agentStatusService->addStatus('Keine neuen Dateien gefunden, Agent hat möglicherweise nur Informationen bereitgestellt.');
            return $this->json([
                'status' => 'completed',
                'message' => 'Agent completed execution.',
                'ai_response' => $aiContent,
                'hint' => 'Check if the agent decided to create a file or just provide information.',
                'statuses' => $this->agentStatusService->getStatuses()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Agent execution failed during post-processing or deployment.', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->agentStatusService->addStatus(sprintf('Kritischer Fehler bei der Agenten-Nachbearbeitung oder Bereitstellung: %s', $e->getMessage()));

            return $this->json([
                'error' => 'Agent execution failed during post-processing or deployment.',
                'details' => $e->getMessage(),
                'statuses' => $this->agentStatusService->getStatuses()
            ], 500);
        }
    }
    

    // OpenAPI Schema for AgentPromptRequest DTO
    #[OA\Schema(
        schema: 'AgentPromptRequest',
        required: ['prompt'],
        properties: [
            new OA\Property(property: 'prompt', type: 'string', example: 'Generate a deployment script for nginx'),
            new OA\Property(property: 'context', type: 'string', nullable: true),
            new OA\Property(property: 'options', type: 'object', nullable: true)
        ]
    )]
    private ?string $agentPromptRequestSchema = null; // Dummy property for schema definition
}

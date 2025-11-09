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


class AiAgentController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService, // Inject the new service
        private ValidatorInterface $validator
    ) {
    }

    #[Route('/api/agent', name: 'api_agent', methods: ['POST'])]
    #[OA\Post(
        path: '/api/agent',
        summary: 'Generate File with AI Agent',
        description: 'Sends a prompt to the AI agent to generate files and potentially deploys them.',
        tags: ['AI Agent'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                
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
                description: 'AI Agent returned no usable content.',
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
            return $this->json([
                'error' => 'Invalid prompt provided.',
                'violations' => $errors,
                'statuses' => $this->agentStatusService->getStatuses()
            ], 400);
        }

        $userPrompt = $agentPromptRequest->prompt;

        try {
            $this->logger->info('Starting file generation with RAG', [
                'prompt' => $userPrompt
            ]);
            $this->agentStatusService->addStatus('Benutzer-Prompt verarbeitet.');
            $this->agentStatusService->addStatus('Prompt an AI-Agent gesendet.');

            $messages = new MessageBag(
                Message::ofUser($userPrompt)
            );

            $result = $agent->call($messages);
            $this->agentStatusService->addStatus('Antwort vom AI-Agent erhalten.');

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

                $this->logger->error('Agent hat keinen Text zurückgegeben', ['raw_result' => $raw]);
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

                $filesToDeploy = [];
                foreach ($recentFiles as $file) {
                    // Assuming a simple mapping for now, adjust target_path as needed
                    // For example, if a PHP class is generated, it might go into src/MyNewClass.php
                    // For a test, it might go into tests/MyNewClassTest.php
                    // This logic needs to be refined based on actual generation patterns.
                    $targetPath = '';
                    if (str_ends_with($file, '.php')) {
                        if (str_ends_with($file, 'Test.php')) {
                             $targetPath = 'tests/'. $file;
                        } else {
                            $targetPath = 'src/'. $file; // Simple heuristic, refine if needed
                        }
                    } elseif (str_ends_with($file, '.yaml') || str_ends_with($file, '.json')) {
                        $targetPath = 'config/'. $file;
                    } else {
                        $targetPath = 'generated_code/'. $file; // Default for other file types
                    }
                    $filesToDeploy[] = ['source_file' => $file, 'target_path' => $targetPath];
                }

                // Call the DeployGeneratedCodeTool
                $deploymentResult = $deployTool->__invoke($filesToDeploy);
                $this->agentStatusService->addStatus('Bereitstellungsskript-Generierung abgeschlossen.');
                
                $this->logger->info('Agent execution completed with file generation and deployment script.', [
                    'response' => $aiContent,
                    'recent_files' => $recentFiles,
                    'deployment_result' => $deploymentResult
                ]);

                return $this->json([
                    'status' => 'success',
                    'message' => 'File generation with RAG successful. Deployment script generated.',
                    'ai_response' => $aiContent,
                    'files_created' => $recentFiles,
                    'deployment_instructions' => $deploymentResult,
                    'statuses' => $this->agentStatusService->getStatuses()
                ]);

            }

            $this->logger->info('Agent execution completed, no new files found.', [
                'response' => $aiContent
            ]);
            $this->agentStatusService->addStatus('Keine neuen Dateien gefunden, Agent hat möglicherweise nur Informationen bereitgestellt.');
            return $this->json([
                'status' => 'completed',
                'message' => 'Agent completed execution.',
                'ai_response' => $aiContent,
                'hint' => 'Check if the agent decided to create a file or just provide information.',
                'statuses' => $this->agentStatusService->getStatuses()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Agent execution failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->agentStatusService->addStatus(sprintf('Kritischer Fehler bei der Agenten-Ausführung: %s', $e->getMessage()));
            
            return $this->json([
                'error' => 'Agent execution failed.', 
                'details' => $e->getMessage(),
                'statuses' => $this->agentStatusService->getStatuses()
            ], 500);
        }
    }

    #[Route('/api/index-knowledge', name: 'api_index_knowledge', methods: ['POST'])]
    #[OA\Post(
        path: '/api/index-knowledge',
        summary: 'Index Knowledge Base',
        description: 'Triggers the indexing of the knowledge base documents into the vector store.',
        tags: ['AI Agent'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Knowledge base indexing initiated successfully.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'success'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'details', type: 'string')
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Failed to index knowledge base.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'details', type: 'string')
                    ]
                )
            )
        ]
    )]
    public function indexKnowledge(
        KnowledgeIndexerTool $indexer
    ): JsonResponse {
        $this->logger->info('Manual knowledge base indexing triggered');

        try {
            $result = $indexer->__invoke();

            if (str_starts_with($result, 'SUCCESS')) {
                return $this->json([
                    'status' => 'success',
                    'message' => 'Knowledge base indexed successfully.',
                    'details' => $result
                ]);
            }

            return $this->json([
                'status' => 'warning',
                'message' => 'Knowledge base indexing completed with warnings.',
                'details' => $result
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Knowledge base indexing failed', [
                'exception' => $e->getMessage()
            ]);
            
            return $this->json([
                'error' => 'Knowledge base indexing failed.', 
                'details' => $e->getMessage(),
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

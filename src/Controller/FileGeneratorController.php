<?php

namespace App\Controller;

use App\Tool\CodeSaverTool;
use App\Tool\KnowledgeIndexerTool;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class FileGeneratorController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[Route('/api/generate-file', name: 'api_generate_file', methods: ['POST'])]
    public function generateFile(
        Request $request,
        #[Autowire(service: 'ai.agent.file_generator')]
        AgentInterface $agent
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $userPrompt = $data['prompt'] ?? null;

        if (!$userPrompt) {
            return $this->json(['error' => 'The "prompt" is missing in the request body.'], 400);
        }

        try {
            $this->logger->info('Starting file generation with RAG', [
                'prompt' => $userPrompt
            ]);

            // Erstelle MessageBag mit User-Prompt
            $messages = new MessageBag(
                Message::ofUser($userPrompt)
            );

            // Agent führt aus:
            // 1. similarity_search -> findet relevante Dokumentation
            // 2. generiert Code basierend auf Dokumentation
            // 3. save_code_file -> speichert die Datei
            $result = $agent->call($messages);
            
            // Prüfe ob Datei erstellt wurde
            $generatedCodeDir = __DIR__ . '/../../generated_code/';
            $recentFiles = [];
            if (is_dir($generatedCodeDir)) {
                $files = scandir($generatedCodeDir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $filepath = $generatedCodeDir . $file;
                    if (filemtime($filepath) > time() - 10) {
                        $recentFiles[] = $file;
                    }
                }
            }

            $this->logger->info('Agent execution completed', [
                'response' => $result->getContent(),
                'recent_files' => $recentFiles
            ]);

            if (!empty($recentFiles)) {
                return $this->json([
                    'status' => 'success',
                    'message' => 'File generation with RAG successful.',
                    'file_path' => $recentFiles[0],
                    'details' => sprintf("File '%s' was created using knowledge base.", $recentFiles[0]),
                    'ai_response' => $result->getContent(),
                    'files_created' => $recentFiles
                ]);
            }

            // Fallback wenn keine Datei gefunden
            return $this->json([
                'status' => 'completed',
                'message' => 'Agent completed execution.',
                'ai_response' => $result->getContent(),
                'hint' => 'Check if the agent decided to create a file or just provide information.'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Agent execution failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->json([
                'error' => 'Agent execution failed.', 
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/api/index-knowledge', name: 'api_index_knowledge', methods: ['POST'])]
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

    
}
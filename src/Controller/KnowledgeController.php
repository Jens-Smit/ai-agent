<?php

namespace App\Controller;

use App\Tool\KnowledgeIndexerTool;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route('/api', name: 'api_')]
#[OA\Tag(name: 'Knowledge Base')]
class KnowledgeController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[Route('/index-knowledge', name: 'index_knowledge', methods: ['POST'])]
    #[OA\Post(
        summary: 'Index Knowledge Base',
        description: 'Triggers the indexing of the knowledge base documents into the vector store.',
        responses: [
            new OA\Response(response: 200, description: 'Knowledge base indexing initiated successfully.'),
            new OA\Response(response: 500, description: 'Failed to index knowledge base.')
        ]
    )]
    public function indexKnowledge(
        KnowledgeIndexerTool $indexer
    ): JsonResponse {
        // Der gesamte Inhalt Ihrer ursprünglichen indexKnowledge-Methode
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

        } catch (\Exception $e){
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
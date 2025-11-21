<?php
// src/Tool/UserKnowledgeSearchTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Repository\UserKnowledgeDocumentRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Durchsucht die persönliche Knowledge Base des aktuellen Users
 * Verwendet semantische Ähnlichkeitssuche mit Vektor-Embeddings
 */
#[AsTool(
    name: 'user_knowledge_search',
    description: 'Searches the personal knowledge base of the current user using semantic similarity. Returns relevant documents that match the query. Use this to find information the user has previously stored.'
)]
final class UserKnowledgeSearchTool
{
    private const EMBEDDING_MODEL = 'text-embedding-004';

    public function __construct(
        private PlatformInterface $platform,
        private UserKnowledgeDocumentRepository $knowledgeRepo,
        private UserRepository $userRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Durchsucht die persönliche Wissensdatenbank des Users
     *
     * @param string $query Die Suchanfrage
     * @param int $limit Maximale Anzahl an Ergebnissen (1-20)
     * @param float $minScore Minimaler Similarity-Score (0.0-1.0)
     * @param string|null $sourceType Filter nach Quelltyp (manual, uploaded_file, url)
     * @return string Gefundene Dokumente als formatierter Text
     */
    public function __invoke(
        string $query,
        #[With(minimum: 1, maximum: 20)]
        int $limit = 5,
        #[With(minimum: 0, maximum: 1)]
        float $minScore = 0.3,
        #[With(enum: ['manual', 'uploaded_file', 'url', ''])]
        string $sourceType = ''
    ): string {
        // Hole User-ID aus globalem Kontext
        $userId = $GLOBALS['current_user_id'] ?? null;

        if (!$userId) {
            return "ERROR: No user context available. Cannot search personal knowledge base.";
        }

        $user = $this->userRepository->find($userId);
        
        if (!$user) {
            return "ERROR: User not found.";
        }

        $this->logger->info('User knowledge search started', [
            'userId' => $userId,
            'query' => substr($query, 0, 100),
            'limit' => $limit,
            'minScore' => $minScore,
            'sourceType' => $sourceType
        ]);

        try {
            // Erstelle Query-Embedding
            $result = $this->platform->invoke(self::EMBEDDING_MODEL, $query);
            $vectors = $result->asVectors();

            if (empty($vectors)) {
                return "ERROR: Could not create embedding for query.";
            }

            $queryEmbedding = $vectors[0]->getData();

            // Suche ähnliche Dokumente
            $results = $this->knowledgeRepo->findSimilarForUser(
                $user,
                $queryEmbedding,
                $limit,
                $minScore
            );

            // Filter nach Quelltyp falls angegeben
            if ($sourceType !== '') {
                $results = array_filter(
                    $results,
                    fn($r) => $r['document']->getSourceType() === $sourceType
                );
                $results = array_values($results);
            }

            if (empty($results)) {
                return sprintf(
                    "No relevant documents found in your personal knowledge base for query: \"%s\"\n\n" .
                    "Tip: You can add knowledge using the 'add_user_knowledge' tool or by uploading documents.",
                    $query
                );
            }

            // Formatiere Ergebnisse
            $output = sprintf(
                "Found %d relevant document(s) in your personal knowledge base for: \"%s\"\n\n",
                count($results),
                $query
            );

            foreach ($results as $idx => $resultData) {
                $doc = $resultData['document'];
                $score = $resultData['score'];

                $output .= sprintf(
                    "=== Result %d (Relevance: %.1f%%) ===\n",
                    $idx + 1,
                    $score * 100
                );

                $output .= sprintf("Title: %s\n", $doc->getTitle());
                $output .= sprintf("Source: %s", $doc->getSourceType());
                
                if ($doc->getSourceReference()) {
                    $output .= sprintf(" (%s)", $doc->getSourceReference());
                }
                $output .= "\n";

                // Tags anzeigen
                $tags = $doc->getTags();
                if (!empty($tags)) {
                    $output .= sprintf("Tags: %s\n", implode(', ', $tags));
                }

                // Content (gekürzt wenn nötig)
                $content = $doc->getContent();
                if (mb_strlen($content) > 500) {
                    $content = mb_substr($content, 0, 500) . '...';
                }
                $output .= sprintf("Content:\n%s\n\n", $content);
            }

            $this->logger->info('User knowledge search completed', [
                'userId' => $userId,
                'results_count' => count($results)
            ]);

            return $output;

        } catch (\Exception $e) {
            $this->logger->error('User knowledge search failed', [
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);

            return sprintf("ERROR: Knowledge search failed: %s", $e->getMessage());
        }
    }
}
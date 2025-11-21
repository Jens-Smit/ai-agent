<?php
// src/Tool/MySQLKnowledgeSearchTool.php

namespace App\Tool;

use App\Repository\KnowledgeDocumentRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'mysql_knowledge_search',
    description: 'Searches the MySQL knowledge base using semantic similarity. Returns relevant documentation chunks that match the query.'
)]
final class MySQLKnowledgeSearchTool
{
    public function __construct(
        private PlatformInterface $platform,
        private KnowledgeDocumentRepository $knowledgeRepo,
        private LoggerInterface $logger
    ) {}

    /**
     * Sucht in der MySQL Wissensdatenbank nach relevanten Dokumenten
     *
     * @param string $query Die Suchanfrage
     * @param int $limit Maximale Anzahl an Ergebnissen (1-20)
     * @param float $minScore Minimaler Similarity-Score (0.0-1.0)
     * @return string Gefundene Dokumente als formatierter Text
     */
    public function __invoke(
        string $query,
        #[With(minimum: 1, maximum: 20)]
        int $limit = 5,
        #[With(minimum: 0, maximum: 1)]
        float $minScore = 0.3
    ): string {
        $this->logger->info('MySQL Knowledge Search started', [
            'query' => substr($query, 0, 100),
            'limit' => $limit,
            'minScore' => $minScore
        ]);

        try {
            // 1. Erstelle Embedding für die Query
            $result = $this->platform->invoke('text-embedding-004', $query);

            // ANPASSUNG: Entferne die fehlerhafte Prüfung isSuccessful(), da DeferredResult sie nicht hat.
            if (!$result) {
                $this->logger->error('Embedding platform invocation failed: Result is null.', ['query' => $query]);
                return "ERROR: Embedding service failed to return a result (null).";
            }
            
            // Die asVectors()-Methode wird nun direkt aufgerufen. Fehler (falls vorhanden) 
            // werden vom catch-Block abgefangen.
            $vectors = $result->asVectors(); 

            if (empty($vectors)) {
                return "ERROR: Could not create embedding for query.";
            }

            // Stelle sicher, dass der erste Vektor existiert
            if (!isset($vectors[0])) {
                return "ERROR: Embedding vector data is missing.";
            }

            $queryEmbedding = $vectors[0]->getData();

            // 2. Suche ähnliche Dokumente in MySQL
            $results = $this->knowledgeRepo->findSimilarFixed(
                $queryEmbedding,
                $limit,
                $minScore
            );

            if (empty($results)) {
                return "No relevant documents found in knowledge base for query: " . $query;
            }

            // 3. Formatiere Ergebnisse
            $output = sprintf(
                "Found %d relevant document(s) for query: \"%s\"\n\n",
                count($results),
                $query
            );

            foreach ($results as $idx => $resultData) {
                // Stelle sicher, dass 'document' und 'score' Schlüssel existieren
                if (!isset($resultData['document']) || !isset($resultData['score'])) {
                    continue; 
                }

                $doc = $resultData['document'];
                $score = $resultData['score'];

                $output .= sprintf(
                    "=== Result %d (Similarity: %.2f%%) ===\n",
                    $idx + 1,
                    $score * 100
                );

                $output .= sprintf(
                    "Source: %s\n",
                    $doc->getSourceFile()
                );

                // Kürze Content wenn zu lang
                $content = $doc->getContent();
                if (strlen($content) > 500) {
                    $content = substr($content, 0, 500) . '...';
                }

                $output .= sprintf("Content:\n%s\n\n", $content);
            }

            $this->logger->info('MySQL Knowledge Search completed', [
                'results_count' => count($results),
                'best_score' => $results[0]['score'] ?? 0
            ]);

            return $output;

        } catch (\Exception $e) {
            $this->logger->error('MySQL Knowledge Search failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return sprintf(
                "ERROR: Knowledge search failed: %s",
                $e->getMessage()
            );
        }
    }
}
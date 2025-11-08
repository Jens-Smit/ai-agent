<?php

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Finder\Finder;
use Symfony\AI\Store\Document\Loader\TextFileLoader;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\StoreInterface;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'index_knowledge_base',
    description: 'Indexes RST and Markdown documentation files from the knowledge base directory into the vector store. Call this once at startup or when documentation changes.'
)]
final class KnowledgeIndexerTool
{
    private const KNOWLEDGE_BASE_DIR = __DIR__.'/../../knowledge_base/';
    
    public function __construct(
        private PlatformInterface $platform,
        private StoreInterface $store,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Indexes all RST and MD files from the knowledge base directory.
     * @return string A message indicating success or failure with statistics.
     */
    public function __invoke(): string
    {
        $this->logger->info('Starting knowledge base indexing', [
            'directory' => self::KNOWLEDGE_BASE_DIR
        ]);

        if (!is_dir(self::KNOWLEDGE_BASE_DIR)) {
            $error = 'Knowledge base directory does not exist: ' . self::KNOWLEDGE_BASE_DIR;
            $this->logger->error($error);
            return "ERROR: $error";
        }

        try {
            // 1. Load
            $finder = new Finder();
            $finder->files()
                ->in(self::KNOWLEDGE_BASE_DIR)
                ->name(['*.md', '*.rst']);

            if (!$finder->hasResults()) {
                $this->logger->warning('No matching .md or .rst documents found in knowledge base directory');
                return "WARNING: No RST or MD files found in " . self::KNOWLEDGE_BASE_DIR;
            }

            $loader = new TextFileLoader();
            $allDocuments = [];

            foreach ($finder as $file) {
                $documentsGenerator = $loader->load($file->getRealPath());
                $documentsFromFile = iterator_to_array($documentsGenerator);
                $allDocuments = array_merge($allDocuments, $documentsFromFile);
            }

            $docCount = count($allDocuments);
            $this->logger->info(sprintf('Found and loaded %d documents', $docCount));
            
            if ($docCount === 0) {
                 $this->logger->warning('No documents loaded despite files being found.');
                 return "WARNING: Files were found, but no documents were loaded.";
            }

            // 2. Transform
            $transformer = new TextSplitTransformer(chunkSize: 1000, overlap: 200);
            $chunks = $transformer->transform($allDocuments);
            $chunksArray = iterator_to_array($chunks);
            $chunkCount = count($chunksArray);

            if ($chunkCount === 0) {
                 $this->logger->warning('Documents were found, but no chunks were created.');
                 return "WARNING: Found $docCount documents, but they resulted in 0 chunks after transformation.";
            }
            
            $this->logger->info(sprintf('Transformed %d documents into %d chunks', $docCount, $chunkCount));

            // 3. Vectorize (Batch-Verarbeitung zur Vermeidung des "Response does not contain data."-Fehlers)
            $vectorizer = new Vectorizer($this->platform, 'text-embedding-004'); 
            
            $this->logger->info('Vectorizing chunks and adding to store in batches...');
            
            $vectorizedChunks = [];
            $batchSize = 50; // Eine sichere Batch-Größe für die Gemini Embedding API
            
            foreach (array_chunk($chunksArray, $batchSize) as $batch) {
                try {
                    $this->logger->info(sprintf('Processing batch of %d chunks.', count($batch)));
                    
                    $vectorizedChunks = array_merge(
                        $vectorizedChunks, 
                        // Wichtig: iterator_to_array() wird auf den Generator angewendet
                        iterator_to_array($vectorizer->vectorize($batch))
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Batch vectorization failed (API response error).', [
                        'exception' => $e->getMessage()
                    ]);
                    // Stoppt den Prozess sofort, wenn ein Batch fehlschlägt
                    throw $e; 
                }
            }
            
            $chunkCount = count($vectorizedChunks); // Aktualisierte Chunk-Anzahl
            
            // 4. Store
            if (!empty($vectorizedChunks)) {
                $this->logger->info('Adding all vectorized chunks to the store...');
                // --- FIX: Dokumente einzeln hinzufügen ---
                foreach ($vectorizedChunks as $chunk) {
                    // $chunk ist hier bereits ein VectorDocument-Objekt
                    $this->store->add($chunk); 
                }
            } else {
                 $this->logger->error('Vectorization resulted in zero chunks to store.');
                 return "ERROR: Vectorization failed or resulted in zero chunks to store.";
            }

            $this->logger->info('Successfully indexed knowledge base', [
                'documents' => $docCount,
                'chunks' => $chunkCount
            ]);
            
            return sprintf(
                "SUCCESS: Indexed %d chunks from %d document(s) from knowledge base into vector store.",
                $chunkCount,
                $docCount
            );
            
        } catch (\Exception $e){
            $this->logger->error('Knowledge base indexing failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return "ERROR: Failed to index knowledge base: " . $e->getMessage();
        }
    }
}
<?php
// src/Tool/KnowledgeIndexerTool.php (Updated für MySQL)

namespace App\Tool;

use App\Entity\KnowledgeDocument;
use App\Repository\KnowledgeDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Finder\Finder;
use Symfony\AI\Store\Document\Loader\TextFileLoader;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\AI\Platform\PlatformInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

#[AsTool(
    name: 'index_knowledge_base',
    description: 'Indexes RST and Markdown documentation files from the knowledge base directory into MySQL vector store. Call this once at startup or when documentation changes.'
)]
final class KnowledgeIndexerTool
{
    private const KNOWLEDGE_BASE_DIR = __DIR__.'/../../knowledge_base/';
    
    public function __construct(
        private PlatformInterface $platform,
        private EntityManagerInterface $em,
        private KnowledgeDocumentRepository $knowledgeRepo,
        private LoggerInterface $logger
    ) {}

    /**
     * Indexes all RST and MD files from the knowledge base directory into MySQL.
     * @return string A message indicating success or failure with statistics.
     */
    public function __invoke(): string
    {
        $this->logger->info('Starting knowledge base indexing to MySQL', [
            'directory' => self::KNOWLEDGE_BASE_DIR
        ]);

        if (!is_dir(self::KNOWLEDGE_BASE_DIR)) {
            $error = 'Knowledge base directory does not exist: ' . self::KNOWLEDGE_BASE_DIR;
            $this->logger->error($error);
            return "ERROR: $error";
        }

        try {
            // 1. Finde alle .md und .rst Dateien
            $finder = new Finder();
            $finder->files()
                ->in(self::KNOWLEDGE_BASE_DIR)
                ->name(['*.md', '*.rst']);

            if (!$finder->hasResults()) {
                $this->logger->warning('No matching documents found');
                return "WARNING: No RST or MD files found in " . self::KNOWLEDGE_BASE_DIR;
            }

            $loader = new TextFileLoader();
            $allDocuments = [];

            foreach ($finder as $file) {
                $documentsGenerator = $loader->load($file->getRealPath());
                $documentsFromFile = iterator_to_array($documentsGenerator);
                
                // Füge Source-File Info zu Metadaten hinzu
                foreach ($documentsFromFile as $doc) {
                    $metadata = $doc->getMetadata()->getArrayCopy();
                    $metadata['source_file'] = $file->getRelativePathname();
                    $doc->getMetadata()->exchangeArray($metadata);
                }
                
                $allDocuments = array_merge($allDocuments, $documentsFromFile);
            }

            $docCount = count($allDocuments);
            $this->logger->info(sprintf('Found and loaded %d documents', $docCount));

            if ($docCount === 0) {
                return "WARNING: Files found, but no documents loaded.";
            }

            // 2. Transform in Chunks
            $transformer = new TextSplitTransformer(chunkSize: 1000, overlap: 200);
            $chunks = $transformer->transform($allDocuments);
            $chunksArray = iterator_to_array($chunks);
            $chunkCount = count($chunksArray);

            if ($chunkCount === 0) {
                return "WARNING: Found $docCount documents, but 0 chunks after transformation.";
            }

            $this->logger->info(sprintf('Transformed into %d chunks', $chunkCount));

            // 3. Vectorize in Batches
            $batchSize = 50;
            $totalSaved = 0;
            
            foreach (array_chunk($chunksArray, $batchSize) as $batch) {
                try {
                    $this->logger->info(sprintf('Processing batch of %d chunks', count($batch)));
                    
                    // Erstelle Embeddings für den Batch
                    $embeddings = [];
                    foreach ($batch as $chunk) {
                        $result = $this->platform->invoke(
                            'text-embedding-004',
                            $chunk->getContent()
                        );
                        
                        $vectors = $result->asVectors();
                        if (!empty($vectors)) {
                            $embeddings[] = [
                                'chunk' => $chunk,
                                'vector' => $vectors[0]->getData()
                            ];
                        }
                    }

                    // 4. Speichere in MySQL
                    foreach ($embeddings as $embeddingData) {
                        $chunk = $embeddingData['chunk'];
                        $vector = $embeddingData['vector'];
                        
                        $metadata = $chunk->getMetadata()->getArrayCopy();
                        $sourceFile = $metadata['source_file'] ?? 'unknown';

                        // Prüfe ob Dokument bereits existiert (based on ID)
                        $existingDoc = $this->knowledgeRepo->find($chunk->getId()->toRfc4122());
                        
                        if ($existingDoc) {
                            // Update
                            $existingDoc->setContent($chunk->getContent());
                            $existingDoc->setEmbedding($vector);
                            $existingDoc->setMetadata($metadata);
                            $existingDoc->setUpdatedAt(new \DateTimeImmutable());
                        } else {
                            // Insert
                            $doc = new KnowledgeDocument();
                            $doc->setId($chunk->getId()->toRfc4122());
                            $doc->setSourceFile($sourceFile);
                            $doc->setContent($chunk->getContent());
                            $doc->setEmbedding($vector);
                            $doc->setMetadata($metadata);
                            
                            $this->em->persist($doc);
                        }
                        
                        $totalSaved++;
                        
                        // Flush alle 10 Dokumente
                        if ($totalSaved % 10 === 0) {
                            $this->em->flush();
                            $this->em->clear(); // Verhindert Memory-Leaks
                        }
                    }
                    
                    $this->em->flush();
                    
                } catch (\Exception $e) {
                    $this->logger->error('Batch processing failed', [
                        'exception' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            $this->logger->info('Successfully indexed knowledge base to MySQL', [
                'documents' => $docCount,
                'chunks' => $chunkCount,
                'saved' => $totalSaved
            ]);

            return sprintf(
                "SUCCESS: Indexed %d chunks from %d document(s) into MySQL knowledge base.",
                $totalSaved,
                $docCount
            );

        } catch (\Exception $e) {
            $this->logger->error('Knowledge base indexing failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return "ERROR: Failed to index knowledge base: " . $e->getMessage();
        }
    }
}
<?php
// src/Tool/AddUserKnowledgeTool.php

declare(strict_types=1);

namespace App\Tool;

use App\Repository\UserRepository;
use App\Service\UserKnowledgeService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;

/**
 * Fügt neues Wissen zur persönlichen Knowledge Base des Users hinzu
 */
#[AsTool(
    name: 'add_user_knowledge',
    description: 'Adds new knowledge to the personal knowledge base of the current user. Use this to store information the user wants to remember, such as preferences, important facts, notes, or any information they want to reference later.'
)]
final class AddUserKnowledgeTool
{
    public function __construct(
        private UserKnowledgeService $knowledgeService,
        private UserRepository $userRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Fügt ein neues Wissensdokument hinzu
     *
     * @param string $title Ein aussagekräftiger Titel für das Wissen
     * @param string $content Der eigentliche Inhalt/das Wissen
     * @param array|null $tags Optional: Tags zur Kategorisierung (z.B. ["arbeit", "kontakte"])
     * @return array Status und Details des erstellten Dokuments
     */
    public function __invoke(
        #[With(minLength: 3, maxLength: 255)]
        string $title,
        #[With(minLength: 10)]
        string $content,
        string $tags = ''
    ): array {
        // Hole User-ID aus globalem Kontext
        $userId = $GLOBALS['current_user_id'] ?? null;

        if (!$userId) {
            $this->logger->error('No user context for adding knowledge');
            return [
                'status' => 'error',
                'message' => 'No user context available. Cannot add to knowledge base.'
            ];
        }

        $user = $this->userRepository->find($userId);
        
        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'User not found.'
            ];
        }

        $this->logger->info('Adding user knowledge', [
            'userId' => $userId,
            'title' => $title,
            'contentLength' => strlen($content),
            'tags' => $tags
        ]);

        try {
            $doc = $this->knowledgeService->addManualKnowledge(
                $user,
                $title,
                $content,
                $tags
            );

            $this->logger->info('Knowledge added successfully', [
                'userId' => $userId,
                'docId' => $doc->getId()
            ]);

            return [
                'status' => 'success',
                'message' => sprintf('Knowledge "%s" successfully added to your personal knowledge base.', $title),
                'document' => [
                    'id' => $doc->getId(),
                    'title' => $doc->getTitle(),
                    'tags' => $doc->getTags(),
                    'created_at' => $doc->getCreatedAt()->format('c')
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to add knowledge', [
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to add knowledge: ' . $e->getMessage()
            ];
        }
    }
}
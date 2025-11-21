<?php
// src/Controller/UserKnowledgeController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserKnowledgeDocument;
use App\Repository\UserKnowledgeDocumentRepository;
use App\Service\UserKnowledgeService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/knowledge', name: 'api_knowledge_')]
#[OA\Tag(name: 'User Knowledge Base')]
class UserKnowledgeController extends AbstractController
{
    public function __construct(
        private UserKnowledgeService $knowledgeService,
        private UserKnowledgeDocumentRepository $knowledgeRepo
    ) {}

    /**
     * Listet alle Wissensdokumente des Users
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste aller persönlichen Wissensdokumente',
        responses: [
            new OA\Response(response: 200, description: 'Dokumentenliste'),
            new OA\Response(response: 401, description: 'Nicht authentifiziert')
        ]
    )]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $documents = $this->knowledgeService->getAllForUser($user);

        return $this->json([
            'status' => 'success',
            'count' => count($documents),
            'documents' => array_map(fn($d) => $this->formatDocument($d), $documents)
        ]);
    }

    /**
     * Fügt neues Wissen hinzu
     */
    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Neues Wissensdokument erstellen',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'content'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Wichtige Kontakte'),
                    new OA\Property(property: 'content', type: 'string', example: 'Liste wichtiger Ansprechpartner...'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['kontakte', 'arbeit'])
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Dokument erstellt'),
            new OA\Response(response: 400, description: 'Ungültige Eingabe'),
            new OA\Response(response: 401, description: 'Nicht authentifiziert')
        ]
    )]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        
        $title = $data['title'] ?? null;
        $content = $data['content'] ?? null;
        $tags = $data['tags'] ?? null;

        if (!$title || !$content) {
            return $this->json([
                'error' => 'Title and content are required'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $doc = $this->knowledgeService->addManualKnowledge(
                $user,
                $title,
                $content,
                $tags
            );

            return $this->json([
                'status' => 'success',
                'message' => 'Knowledge document created',
                'document' => $this->formatDocument($doc)
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to create document: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sucht in der Knowledge Base
     */
    #[Route('/search', name: 'search', methods: ['POST'])]
    #[OA\Post(
        summary: 'Semantische Suche in der Knowledge Base',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['query'],
                properties: [
                    new OA\Property(property: 'query', type: 'string', example: 'Kontaktdaten von Max'),
                    new OA\Property(property: 'limit', type: 'integer', example: 5),
                    new OA\Property(property: 'min_score', type: 'number', example: 0.3)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Suchergebnisse'),
            new OA\Response(response: 401, description: 'Nicht authentifiziert')
        ]
    )]
    public function search(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        
        $query = $data['query'] ?? '';
        $limit = min(20, max(1, $data['limit'] ?? 5));
        $minScore = min(1.0, max(0.0, $data['min_score'] ?? 0.3));

        if (empty(trim($query))) {
            return $this->json([
                'error' => 'Query is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $results = $this->knowledgeService->search($user, $query, $limit, $minScore);

            return $this->json([
                'status' => 'success',
                'query' => $query,
                'count' => count($results),
                'results' => array_map(function($r) {
                    return [
                        'document' => $this->formatDocument($r['document']),
                        'score' => round($r['score'], 4)
                    ];
                }, $results)
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Search failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Holt ein einzelnes Dokument
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        summary: 'Einzelnes Wissensdokument abrufen',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokument'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function get(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->knowledgeRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        return $this->json([
            'status' => 'success',
            'document' => $this->formatDocument($doc, true)
        ]);
    }

    /**
     * Aktualisiert ein Dokument
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Wissensdokument aktualisieren',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dokument aktualisiert'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->knowledgeRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        try {
            $updatedDoc = $this->knowledgeService->updateKnowledge(
                $doc,
                $data['title'] ?? null,
                $data['content'] ?? null,
                $data['tags'] ?? null
            );

            return $this->json([
                'status' => 'success',
                'message' => 'Document updated',
                'document' => $this->formatDocument($updatedDoc)
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Update failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Löscht ein Dokument (Soft-Delete)
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Wissensdokument löschen',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokument gelöscht'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->knowledgeRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        $this->knowledgeService->deleteKnowledge($doc);

        return $this->json([
            'status' => 'success',
            'message' => 'Document deleted'
        ]);
    }

    /**
     * Statistiken zur Knowledge Base
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[OA\Get(
        summary: 'Statistiken zur persönlichen Knowledge Base',
        responses: [
            new OA\Response(response: 200, description: 'Statistiken')
        ]
    )]
    public function stats(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $stats = $this->knowledgeService->getStats($user);

        return $this->json([
            'status' => 'success',
            'stats' => $stats
        ]);
    }

    private function formatDocument(UserKnowledgeDocument $doc, bool $includeContent = false): array
    {
        $data = [
            'id' => $doc->getId(),
            'title' => $doc->getTitle(),
            'source_type' => $doc->getSourceType(),
            'source_reference' => $doc->getSourceReference(),
            'tags' => $doc->getTags(),
            'created_at' => $doc->getCreatedAt()->format('c'),
            'updated_at' => $doc->getUpdatedAt()->format('c')
        ];

        if ($includeContent) {
            $data['content'] = $doc->getContent();
            $data['metadata'] = $doc->getMetadata();
        }

        return $data;
    }
}
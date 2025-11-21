<?php
// src/Controller/UserDocumentController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserDocument;
use App\Repository\UserDocumentRepository;
use App\Service\UserDocumentService;
use App\Service\UserKnowledgeService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/documents', name: 'api_documents_')]
#[OA\Tag(name: 'User Documents')]
class UserDocumentController extends AbstractController
{
    public function __construct(
        private UserDocumentService $documentService,
        private UserDocumentRepository $documentRepo,
        private UserKnowledgeService $knowledgeService
    ) {}

    /**
     * Listet alle Dokumente des Users
     */
    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Liste aller hochgeladenen Dokumente',
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['template', 'attachment', 'reference', 'media', 'other'])),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pdf', 'document', 'spreadsheet', 'image', 'text', 'other'])),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 50))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokumentenliste'),
            new OA\Response(response: 401, description: 'Nicht authentifiziert')
        ]
    )]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $category = $request->query->get('category');
        $type = $request->query->get('type');
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        $documents = $this->documentRepo->findByUser($user, $category, $limit);

        if ($type) {
            $documents = array_filter($documents, fn($d) => $d->getDocumentType() === $type);
        }

        return $this->json([
            'status' => 'success',
            'count' => count($documents),
            'documents' => array_map(fn($d) => $this->formatDocument($d), array_values($documents))
        ]);
    }

    /**
     * Lädt ein Dokument hoch
     */
    #[Route('', name: 'upload', methods: ['POST'])]
    #[OA\Post(
        summary: 'Dokument hochladen',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'category', type: 'string', enum: ['template', 'attachment', 'reference', 'media', 'other']),
                        new OA\Property(property: 'display_name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'tags', type: 'string', description: 'Komma-getrennte Tags'),
                        new OA\Property(property: 'index_to_knowledge', type: 'boolean', description: 'In Knowledge Base indizieren')
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Dokument hochgeladen'),
            new OA\Response(response: 400, description: 'Ungültige Datei'),
            new OA\Response(response: 401, description: 'Nicht authentifiziert')
        ]
    )]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $category = $request->request->get('category', UserDocument::CATEGORY_OTHER);
        $displayName = $request->request->get('display_name');
        $description = $request->request->get('description');
        $tagsString = $request->request->get('tags', '');
        $indexToKnowledge = filter_var($request->request->get('index_to_knowledge', false), FILTER_VALIDATE_BOOLEAN);

        $tags = !empty($tagsString) 
            ? array_map('trim', explode(',', $tagsString)) 
            : null;

        try {
            $doc = $this->documentService->upload(
                $user,
                $file,
                $category,
                $displayName,
                $description,
                $tags
            );

            // Optional: In Knowledge Base indizieren
            if ($indexToKnowledge && !empty($doc->getExtractedText())) {
                $this->knowledgeService->indexUploadedDocument($user, $doc, $tags);
            }

            return $this->json([
                'status' => 'success',
                'message' => 'Document uploaded successfully',
                'document' => $this->formatDocument($doc, true)
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Sucht in Dokumenten
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    #[OA\Get(
        summary: 'Dokumente durchsuchen',
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Suchergebnisse')
        ]
    )]
    public function search(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $query = $request->query->get('q', '');
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        if (empty(trim($query))) {
            return $this->json(['error' => 'Search query required'], Response::HTTP_BAD_REQUEST);
        }

        $documents = $this->documentRepo->searchByUser($user, $query, $limit);

        return $this->json([
            'status' => 'success',
            'query' => $query,
            'count' => count($documents),
            'documents' => array_map(fn($d) => $this->formatDocument($d), $documents)
        ]);
    }

    /**
     * Holt Dokument-Details
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    #[OA\Get(
        summary: 'Dokument-Details abrufen',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokument-Details'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function get(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->documentRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        return $this->json([
            'status' => 'success',
            'document' => $this->formatDocument($doc, true)
        ]);
    }

    /**
     * Lädt Dokument herunter
     */
    #[Route('/{id}/download', name: 'download', methods: ['GET'])]
    #[OA\Get(
        summary: 'Dokument herunterladen',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datei-Download'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function download(int $id): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->documentRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Document not found'], 404);
        }

        $filePath = $doc->getFullPath();

        if (!file_exists($filePath)) {
            return new JsonResponse(['error' => 'File not found on disk'], 404);
        }

        $doc->recordAccess();
        $this->documentRepo->getEntityManager()->flush();

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $doc->getOriginalFilename()
        );

        return $response;
    }

    /**
     * Aktualisiert Dokument-Metadaten
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Dokument-Metadaten aktualisieren',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'display_name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'category', type: 'string'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dokument aktualisiert'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->documentRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        $updatedDoc = $this->documentService->update(
            $doc,
            $data['display_name'] ?? null,
            $data['description'] ?? null,
            $data['category'] ?? null,
            $data['tags'] ?? null
        );

        return $this->json([
            'status' => 'success',
            'message' => 'Document updated',
            'document' => $this->formatDocument($updatedDoc)
        ]);
    }

    /**
     * Löscht ein Dokument
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Dokument löschen',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokument gelöscht'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->documentRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        $this->documentService->delete($doc);

        return $this->json([
            'status' => 'success',
            'message' => 'Document deleted'
        ]);
    }

    /**
     * Indiziert Dokument in Knowledge Base
     */
    #[Route('/{id}/index', name: 'index', methods: ['POST'])]
    #[OA\Post(
        summary: 'Dokument in Knowledge Base indizieren',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Dokument indiziert'),
            new OA\Response(response: 400, description: 'Kein Text zum Indizieren'),
            new OA\Response(response: 404, description: 'Nicht gefunden')
        ]
    )]
    public function indexToKnowledge(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $doc = $this->documentRepo->find($id);

        if (!$doc || $doc->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Document not found'], 404);
        }

        if (empty($doc->getExtractedText())) {
            return $this->json([
                'error' => 'Document has no extractable text for indexing'
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $tags = $data['tags'] ?? null;

        try {
            $knowledgeDocs = $this->knowledgeService->indexUploadedDocument($user, $doc, $tags);

            return $this->json([
                'status' => 'success',
                'message' => sprintf('%d knowledge documents created', count($knowledgeDocs)),
                'chunks_created' => count($knowledgeDocs)
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Indexing failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Speicherstatistiken
     */
    #[Route('/storage/stats', name: 'storage_stats', methods: ['GET'])]
    #[OA\Get(
        summary: 'Speicherstatistiken abrufen',
        responses: [
            new OA\Response(response: 200, description: 'Statistiken')
        ]
    )]
    public function storageStats(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $stats = $this->documentService->getStorageStats($user);

        return $this->json([
            'status' => 'success',
            'storage' => $stats
        ]);
    }

    private function formatDocument(UserDocument $doc, bool $includeDetails = false): array
    {
        $data = [
            'id' => $doc->getId(),
            'name' => $doc->getDisplayName() ?? $doc->getOriginalFilename(),
            'original_filename' => $doc->getOriginalFilename(),
            'type' => $doc->getDocumentType(),
            'mime_type' => $doc->getMimeType(),
            'category' => $doc->getCategory(),
            'size' => $doc->getHumanReadableSize(),
            'size_bytes' => $doc->getFileSize(),
            'tags' => $doc->getTags(),
            'is_indexed' => $doc->isIndexed(),
            'created_at' => $doc->getCreatedAt()->format('c')
        ];

        if ($includeDetails) {
            $data['description'] = $doc->getDescription();
            $data['metadata'] = $doc->getMetadata();
            $data['has_extracted_text'] = !empty($doc->getExtractedText());
            $data['access_count'] = $doc->getAccessCount();
            $data['last_accessed'] = $doc->getLastAccessedAt()?->format('c');
            $data['updated_at'] = $doc->getUpdatedAt()->format('c');
        }

        return $data;
    }
}
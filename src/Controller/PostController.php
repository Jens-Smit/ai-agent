<?php
// src/Controller/PostController.php

namespace App\Controller;

use OpenApi\Attributes as OA; // Wichtig: Nutzt den Attributes-Namespace
use App\DTO\PostCreateDTO;
use App\DTO\PostUpdateDTO;
use App\Entity\Post;
use App\Entity\Category;
use App\Service\PostService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route; // Nutzt den Attribute-Namespace
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Bundle\SecurityBundle\Security; 
use Symfony\Component\HttpFoundation\File\UploadedFile;
// use Nelmio\ApiDocBundle\Annotation\Model; // Nicht mehr benötigt, da durch OA\JsonContent(ref: Post::class) ersetzt

class PostController extends AbstractController
{
    #[Route('/api/posts', name: 'get_posts', methods: ['GET'])]
    #[OA\Get(
        path: "/api/posts",
        summary: "Alle Blogposts abrufen",
        tags: ["Posts"],
        parameters: [
            new OA\Parameter(
                name: "categoryId",
                in: "query",
                description: "Filter nach Kategorie ID",
                required: false,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Liste aller Posts",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(
                        ref: Post::class // Referenziert die Entity-Klasse, NelmioApiDocBundle sollte Serializer-Groups anwenden
                    )
                )
            )
        ]
    )]
    public function index(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        $categoryId = $request->query->get('categoryId');
        
        if ($categoryId) {
            $posts = $em->getRepository(Post::class)->findBy(['category' => $categoryId]);
        } else {
            $posts = $em->getRepository(Post::class)->findAll();
        }
        
        $json = $serializer->serialize($posts, 'json', ['groups' => 'post']);
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    #[Route('/api/posts/{slug}', name: 'get_post_by_id', methods: ['GET'])]
    #[OA\Get(
        path: "/api/posts/{slug}",
        summary: "Einen Blogpost per Slug abrufen",
        tags: ["Posts"],
        parameters: [
            new OA\Parameter(
                name: "slug",
                in: "path",
                description: "Slug des Blogposts",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Details des Blogposts", content: new OA\JsonContent(ref: Post::class)),
            new OA\Response(response: 404, description: "Post nicht gefunden")
        ]
    )]
    public function getPostBySlug(string $slug, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        $post = $em->getRepository(Post::class)->findOneBy(['slug' => $slug]);

        if (!$post) {
            return new JsonResponse(['error' => 'Post nicht gefunden'], 404);
        }

        $json = $serializer->serialize($post, 'json', ['groups' => 'post']);
        return new JsonResponse($json, 200, [], true);
    }

    #[Route('/api/posts', name: 'create_post', methods: ['POST'])]
    #[OA\Post(
        path: "/api/posts",
        summary: "Neuen Blogpost erstellen",
        tags: ["Posts"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: "title", type: "string", example: "Mein erster Post"),
                        new OA\Property(property: "content", type: "string", example: "Das ist der Inhalt"),
                        new OA\Property(property: "categoryId", type: "integer", example: 1),
                        new OA\Property(property: "titleImage", type: "string", format: "binary"),
                        new OA\Property(
                            property: "images",
                            type: "array",
                            items: new OA\Items(type: "string", format: "binary")
                        )
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Post erfolgreich erstellt"),
            new OA\Response(response: 400, description: "Titel ist erforderlich"),
            new OA\Response(response: 404, description: "Kategorie nicht gefunden"),
            new OA\Response(response: 500, description: "Serverfehler"),
        ],
        security: [['bearerAuth' => []]]
    )]
    public function create(Request $request, PostService $postService, Security $security): JsonResponse
    {
        $uploadedImages = $request->files->get('images', []);
        
        if (!is_array($uploadedImages) && $uploadedImages instanceof UploadedFile) {
            $uploadedImages = [$uploadedImages];
        } elseif ($uploadedImages === null) {
            $uploadedImages = [];
        }

        $categoryId = $request->request->get('categoryId');
        
        $dto = new PostCreateDTO(
            $request->request->get('title', ''),
            $request->request->get('content', null),
            $request->files->get('titleImage'),
            $uploadedImages,
            $request->request->get('imageMap', '{}'),
            $categoryId ? (int)$categoryId : null
        );

        if (!$dto->title) {
            return new JsonResponse(['error' => 'Titel ist erforderlich.'], 400);
        }

        try {
            $post = $postService->createPost($dto, $security->getUser());
            return new JsonResponse(['message' => 'Post erfolgreich erstellt', 'id' => $post->getId()], 201);
        } catch (\Throwable $e) {
            error_log('Fehler beim Erstellen des Posts: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new JsonResponse(['error' => 'Fehler beim Erstellen des Posts: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/api/posts/upload', name: 'upload_media', methods: ['POST'])]
    #[OA\Post(
        path: "/api/posts/upload",
        summary: "Mediendatei hochladen",
        tags: ["Uploads"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: "file", type: "string", format: "binary")
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Upload erfolgreich, URL der Datei zurückgegeben"),
            new OA\Response(response: 400, description: "Keine Datei vorhanden"),
            new OA\Response(response: 500, description: "Fehler beim Upload"),
        ],
        security: [['bearerAuth' => []]]
    )]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'Keine Datei vorhanden.'], 400);
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads';
        $filename = 'media-' . uniqid() . '.' . $file->guessExtension();

        try {
            $file->move($uploadDir, $filename);
            $url = $request->getSchemeAndHttpHost() . '/api/public/uploads/' . $filename;
            return new JsonResponse(['url' => $url], 201);
        } catch (\Exception $e) {
            error_log('Fehler beim Upload der Mediendatei: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new JsonResponse(['error' => 'Fehler beim Upload: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/api/posts/{id}', name: 'update_post', methods: ['POST'])]
    #[OA\Post(
        path: "/api/posts/{id}",
        summary: "Blogpost aktualisieren",
        tags: ["Posts"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "ID des Posts",
                schema: new OA\Schema(type: "integer")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "title", type: "string", example: "Aktualisierter Titel"),
                    new OA\Property(property: "content", type: "string", example: "Neuer Inhalt"),
                    new OA\Property(property: "categoryId", type: "integer", example: 1)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Post erfolgreich aktualisiert"),
            new OA\Response(response: 400, description: "Titel ist erforderlich"),
            new OA\Response(response: 403, description: "Keine Berechtigung"),
            new OA\Response(response: 404, description: "Post nicht gefunden"),
            new OA\Response(response: 500, description: "Serverfehler"),
        ],
        security: [['bearerAuth' => []]]
    )]
    public function update(int $id, Request $request, EntityManagerInterface $em, PostService $postService, Security $security): JsonResponse
    {
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return new JsonResponse(['error' => 'Post nicht gefunden'], 404);
        }

        $currentUser = $security->getUser();
        if ($post->getAuthor() !== $currentUser) {
            return new JsonResponse(['error' => 'Keine Berechtigung'], 403);
        }
        
        $data = json_decode($request->getContent(), true);

        $title = $data['title'] ?? null;
        $content = $data['content'] ?? null;
        $categoryId = isset($data['categoryId']) ? (int)$data['categoryId'] : null;

        if (empty($title)) {
            return new JsonResponse(['error' => 'kein Titel hinterlegt'], 402);
        }
        if (empty($content)) {
            return new JsonResponse(['error' => 'kein Content hinterlegt'], 402);
        }

        $dto = new PostUpdateDTO(
            id: $id,
            title: $title,
            content: $content,
            categoryId: $categoryId
        );

        try {
            $updatedPost = $postService->updatePost($post, $dto);
            return new JsonResponse([
                'message' => 'Post erfolgreich aktualisiert',
                'id' => $updatedPost->getId()
            ]);
        } catch (\Throwable $e) {
            error_log('Fehler beim Aktualisieren des Posts: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Interner Serverfehler beim Update'], 500);
        }
    }

    #[Route('/api/posts/{id}', name: 'delete_post', methods: ['DELETE'])]
    #[OA\Delete(
        path: "/api/posts/{id}",
        summary: "Blogpost löschen",
        tags: ["Posts"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                description: "ID des zu löschenden Posts",
                required: true,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Post gelöscht"),
            new OA\Response(response: 403, description: "Keine Berechtigung"),
            new OA\Response(response: 404, description: "Post nicht gefunden"),
        ],
        security: [['bearerAuth' => []]]
    )]
    public function delete(int $id, EntityManagerInterface $em, Security $security): JsonResponse
    {
        $post = $em->getRepository(Post::class)->find($id);
        if (!$post) {
            return new JsonResponse(['error' => 'Post nicht gefunden'], 404);
        }

        if ($post->getAuthor() !== $security->getUser()) {
            return new JsonResponse(['error' => 'Keine Berechtigung'], 403);
        }

        $em->remove($post);
        $em->flush();

        return new JsonResponse(['message' => 'Post gelöscht']);
    }
}

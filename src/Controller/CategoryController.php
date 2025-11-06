<?php
// src/Controller/CategoryController.php

namespace App\Controller;

// WICHTIG: Ersetze OpenApi\Annotations durch OpenApi\Attributes
use OpenApi\Attributes as OA;
use App\DTO\CategoryCreateDTO;
use App\DTO\CategoryUpdateDTO;
use App\Entity\Category;
use App\Entity\Post;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
// Verwende Symfony\Component\Routing\Attribute\Route
use Symfony\Component\Routing\Attribute\Route; 
use Symfony\Component\Serializer\SerializerInterface;
// Importiere Doctrine Collections für die korrekte Typhinweis
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Exception\ORMException;
// Importiere das Model-Attribut von Nelmio für @Model-Referenzen
use Nelmio\ApiDocBundle\Attribute\Model;
use Throwable;

class CategoryController extends AbstractController
{
    // --- 1. ALLE KATEGORIEN ABRUFEN (GET /api/categories) ---
    #[Route('/api/categories', name: 'get_categories', methods: ['GET'])]
    #[OA\Get(
        path: "/api/categories",
        summary: "Alle Kategorien abrufen",
        tags: ["Categories"],
        parameters: [
            new OA\Parameter(
                name: "parentId",
                in: "query",
                description: "Filter nach Parent-Kategorie ID (null für Root-Kategorien)",
                required: false,
                schema: new OA\Schema(type: "integer", nullable: true)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Liste aller Kategorien",
                content: new OA\JsonContent(
                    type: "array",
                    items: new OA\Items(ref: new Model(type: Category::class))
                )
            )
        ]
    )]
    public function index(Request $request, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        $parentId = $request->query->get('parentId');
        
        if ($parentId !== null) {
            if ($parentId === 'null' || $parentId === '') {
                $categories = $em->getRepository(Category::class)->findBy(['parent' => null]);
            } else {
                $parent = $em->getRepository(Category::class)->find((int)$parentId);
                if (!$parent) {
                    return new JsonResponse(['error' => 'Parent-Kategorie nicht gefunden'], 404);
                }
                $categories = $em->getRepository(Category::class)->findBy(['parent' => $parent]);
            }
        } else {
            $categories = $em->getRepository(Category::class)->findAll();
        }
        
        $json = $serializer->serialize($categories, 'json', [
            'groups' => ['category:read'] 
        ]);
        
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    // --- 2. KATEGORIE NACH ID ABRUFEN (GET /api/categories/{id}) ---
    #[Route('/api/categories/{id}', name: 'get_category_by_id', methods: ['GET'])]
    #[OA\Get(
        path: "/api/categories/{id}",
        summary: "Eine einzelne Kategorie nach ID abrufen",
        tags: ["Categories"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                description: "ID der Kategorie",
                required: true,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Details der Kategorie",
                content: new OA\JsonContent(ref: new Model(type: Category::class))
            ),
            new OA\Response(response: 404, description: "Kategorie nicht gefunden")
        ]
    )]
    public function getCategoryById(int $id, EntityManagerInterface $em, SerializerInterface $serializer): JsonResponse
    {
        $category = $em->getRepository(Category::class)->find($id);

        if (!$category) {
            return new JsonResponse(['error' => 'Kategorie nicht gefunden'], Response::HTTP_NOT_FOUND);
        }
        // Beibehalten des Serializer-Aufrufs mit Gruppen
        $json = $serializer->serialize($category, 'json', ['groups' => 'category:read']);
        
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    // --- 3. KATEGORIE-HIERARCHIE ABRUFEN (GET /api/categories/{id}/tree) ---
    #[Route('/api/categories/{id}/tree', name: 'get_category_tree', methods: ['GET'])]
    #[OA\Get(
        path: "/api/categories/{id}/tree",
        summary: "Kategorie-Hierarchie abrufen",
        tags: ["Categories"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                description: "ID der Parent-Kategorie (oder 'root' für Top-Level)",
                required: true,
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Hierarchische Struktur der Kategorien",
                content: new OA\JsonContent(type: "array", items: new OA\Items(
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "parentId", type: "integer", nullable: true),
                        new OA\Property(property: "postCount", type: "integer"),
                        // Die 'children' Property kann rekursiv definiert werden,
                        // aber für eine einfache Darstellung lassen wir sie generisch.
                        new OA\Property(property: "children", type: "array", items: new OA\Items(type: "object")) 
                    ]
                ))
            ),
            new OA\Response(response: 404, description: "Kategorie nicht gefunden")
        ]
    )]
    public function getCategoryTree(string $id, EntityManagerInterface $em): JsonResponse
    {
        $repository = $em->getRepository(Category::class);

        // Lade alle Kategorien einmal (vermeidet Lazy-Loading-Fallen)
        $allCategories = $repository->findAll();

        // ... (Der Rest der Logik zur Baumstruktur-Erstellung bleibt unverändert) ...

        // Map: id => ['entity' => Category, 'children' => [], 'parentId' => ?int]
        $map = [];
        foreach ($allCategories as $cat) {
            $map[$cat->getId()] = [
                'entity' => $cat,
                'children' => [],
                'parentId' => $cat->getParent()?->getId(),
            ];
        }

        // Fülle die children-Listen
        foreach ($map as $cid => $row) {
            $pid = $row['parentId'];
            if ($pid !== null && isset($map[$pid])) {
                $map[$pid]['children'][] = $cid;
            }
        }

        // Bestimme Startknoten(s)
        $startIds = [];
        if ($id === 'root') {
            foreach ($map as $cid => $row) {
                if ($row['parentId'] === null) {
                    $startIds[] = $cid;
                }
            }
        } else {
            $intId = (int)$id;
            if (!isset($map[$intId])) {
                return new JsonResponse(['error' => 'Kategorie nicht gefunden'], 404);
            }
            $startIds[] = $intId;
        }

        // Rekursiver Builder (arbeitet auf der Map, vermeidet zusätzliche Queries)
        $buildNode = function(int $cid) use (&$buildNode, $map) {
            $cat = $map[$cid]['entity'];
            $children = [];
            foreach ($map[$cid]['children'] as $childId) {
                $children[] = $buildNode($childId);
            }
            return [
                'id' => $cat->getId(),
                'name' => $cat->getName(),
                'parentId' => $cat->getParent()?->getId(),
                'children' => $children,
                'postCount' => $cat->getPosts()->count()
            ];
        };

        $tree = [];
        foreach ($startIds as $sid) {
            $tree[] = $buildNode($sid);
        }

        return new JsonResponse($tree);
    }


    // --- 4. KATEGORIE ERSTELLEN (POST /api/categories) ---
    #[Route('/api/categories', name: 'create_category', methods: ['POST'])]
    #[OA\Post(
        path: "/api/categories",
        summary: "Neue Kategorie erstellen",
        tags: ["Categories"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Technologie"),
                    new OA\Property(property: "parentId", type: "integer", nullable: true, example: null)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Kategorie erfolgreich erstellt"),
            new OA\Response(response: 400, description: "Name ist erforderlich oder Parent nicht gefunden"),
            new OA\Response(response: 500, description: "Serverfehler"),
        ],
        security: [["bearerAuth" => []]]
    )]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $name = $data['name'] ?? null;
        $parentId = $data['parentId'] ?? null;

        if (empty($name)) {
            return new JsonResponse(['error' => 'Name ist erforderlich'], 400);
        }

        $dto = new CategoryCreateDTO($name, $parentId);

        try {
            $category = new Category();
            $category->setName($dto->name);

            if ($dto->parentId) {
                $parent = $em->getRepository(Category::class)->find($dto->parentId);
                if (!$parent) {
                    return new JsonResponse(['error' => 'Parent-Kategorie nicht gefunden'], 400);
                }
                $category->setParent($parent);
            }

            $em->persist($category);
            $em->flush();

            return new JsonResponse([
                'message' => 'Kategorie erfolgreich erstellt',
                'id' => $category->getId()
            ], 201);
        } catch (\Throwable $e) {
            error_log('Fehler beim Erstellen der Kategorie: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return new JsonResponse(['error' => 'Fehler beim Erstellen der Kategorie: ' . $e->getMessage()], 500);
        }
    }

    // --- 5. KATEGORIE AKTUALISIEREN (PUT /api/categories/{id}) ---
    #[Route('/api/categories/{id}', name: 'update_category', methods: ['PUT'])]
    #[OA\Put(
        path: "/api/categories/{id}",
        summary: "Kategorie aktualisieren",
        tags: ["Categories"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "ID der Kategorie",
                schema: new OA\Schema(type: "integer")
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Aktualisierter Name"),
                    new OA\Property(property: "parentId", type: "integer", nullable: true, example: 1)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Kategorie erfolgreich aktualisiert"),
            new OA\Response(response: 400, description: "Ungültige Daten oder Zirkelbezug erkannt"),
            new OA\Response(response: 404, description: "Kategorie nicht gefunden"),
            new OA\Response(response: 500, description: "Serverfehler"),
        ],
        security: [["bearerAuth" => []]]
    )]
    public function update(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $category = $em->getRepository(Category::class)->find($id);
        if (!$category) {
            return new JsonResponse(['error' => 'Kategorie nicht gefunden'], 404);
        }

        $data = json_decode($request->getContent(), true);

        $name = $data['name'] ?? null;
        $parentId = $data['parentId'] ?? null;

        if (empty($name)) {
            return new JsonResponse(['error' => 'Name ist erforderlich'], 400);
        }

        $dto = new CategoryUpdateDTO($id, $name, $parentId);

        try {
            $category->setName($dto->name);

            if ($dto->parentId !== null) {
                if ($dto->parentId === $id) {
                    return new JsonResponse(['error' => 'Eine Kategorie kann nicht ihr eigenes Parent sein'], 400);
                }

                $parent = $em->getRepository(Category::class)->find($dto->parentId);
                if (!$parent) {
                    return new JsonResponse(['error' => 'Parent-Kategorie nicht gefunden'], 400);
                }
                // ... (Logik zur Zirkelbezugsprüfung bleibt unverändert) ...
                
                $current = $parent;
                while ($current !== null) {
                    if ($current->getId() === $category->getId()) {
                        return new JsonResponse(['error' => 'Zirkelbezug erkannt: Parent-Kategorie kann keine Unterkategorie sein (oder umgekehrt)'], 400);
                    }
                    $current = $current->getParent();
                }

                $category->setParent($parent);
                
                // Wegen des Lazy Loading muss die rekursive Funktion alle Kinder initialisieren, damit die Schleife funktioniert.
                $this->initializeCategoryChildren([$category]);

                $checkCircular = function(Category $cat, int $targetId) use (&$checkCircular) {
                    // Prüfe die aktuelle Kategorie und ihre Kinder rekursiv
                    if ($cat->getId() === $targetId) {
                        return true;
                    }
                    // Wegen Lazy Loading muss getCategories() initialisiert werden
                    foreach ($cat->getCategories() as $child) {
                        if ($checkCircular($child, $targetId)) {
                            return true;
                        }
                    }
                    return false;
                };

                // Die Prüfung muss von der *aktuellen* Kategorie ($category) ausgehen und prüfen, 
                // ob der *neue Parent* ($dto->parentId) in der Nachfolgerkette liegt.
                // Wenn $parent ein Kind von $category ist, ist es ein Zirkelbezug.
                if ($checkCircular($category, $dto->parentId)) {
                    return new JsonResponse(['error' => 'Zirkelbezug erkannt: Parent-Kategorie kann keine Unterkategorie sein (oder umgekehrt)'], 400);
                }

                $category->setParent($parent);
            } else {
                $category->setParent(null);
            }

            $em->flush();

            return new JsonResponse([
                'message' => 'Kategorie erfolgreich aktualisiert',
                'id' => $category->getId()
            ]);
        } catch (\Throwable $e) {
            error_log('Fehler beim Aktualisieren der Kategorie: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Interner Serverfehler beim Update'], 500);
        }
    }

    // --- 6. KATEGORIE LÖSCHEN (DELETE /api/categories/{id}) ---
    #[Route('/api/categories/{id}', name: 'delete_category', methods: ['DELETE'])]
    #[OA\Delete(
        path: "/api/categories/{id}",
        summary: "Kategorie löschen",
        tags: ["Categories"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                description: "ID der zu löschenden Kategorie",
                required: true,
                schema: new OA\Schema(type: "integer")
            ),
            new OA\Parameter(
                name: "force",
                in: "query",
                description: "Löschen erzwingen (auch mit Unterkategorien/Posts)",
                required: false,
                schema: new OA\Schema(type: "boolean")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Kategorie gelöscht"),
            new OA\Response(response: 400, description: "Kategorie hat Unterkategorien oder Posts"),
            new OA\Response(response: 404, description: "Kategorie nicht gefunden"),
        ],
        security: [["bearerAuth" => []]]
    )]
    public function delete(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $category = $em->getRepository(Category::class)->find($id);
        if (!$category) {
            return new JsonResponse(['error' => 'Kategorie nicht gefunden'], 404);
        }

        $force = $request->query->get('force', false);
        if (is_string($force)) {
            $force = filter_var($force, FILTER_VALIDATE_BOOLEAN);
        }

        // ... (Logik zur Überprüfung von Unterkategorien und Posts bleibt unverändert) ...

        // Zähle Unterkategorien via Query (robust, ohne PersistentCollection-Initialisierung)
        $subCount = (int) $em->getRepository(Category::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.parent = :cat')
            ->setParameter('cat', $category)
            ->getQuery()
            ->getSingleScalarResult();

        if ($subCount > 0 && !$force) {
            return new JsonResponse([
                'error' => 'Kategorie hat Unterkategorien',
                'subcategoryCount' => $subCount,
                'hint' => 'Verwende force=true zum Löschen mit Unterkategorien'
            ], 400);
        }

        // Zähle Posts via Query
        $postCount = (int) $em->getRepository(\App\Entity\Post::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.category = :cat')
            ->setParameter('cat', $category)
            ->getQuery()
            ->getSingleScalarResult();

        if ($postCount > 0 && !$force) {
            return new JsonResponse([
                'error' => 'Kategorie hat zugeordnete Posts',
                'postCount' => $postCount,
                'hint' => 'Verwende force=true zum Löschen oder ordne Posts einer anderen Kategorie zu'
            ], 400);
        }

        try {
            if ($force) {
                // Lösche gesamte Unterbaum-Struktur sicher (Posts zuerst, dann Kategorien in Kinder-vor-Eltern Reihenfolge)
                $this->deleteRecursive($category, $em);
            } else {
                $em->remove($category);
                $em->flush();
            }

            return new JsonResponse(['message' => 'Kategorie gelöscht']);
        } catch (\Throwable $e) {
            error_log('Fehler beim Löschen der Kategorie: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Fehler beim Löschen der Kategorie'], 500);
        }
    }


    /**
     * Hilfsmethode zum rekursiven Löschen von Kategorien (Posts und Kinder zuerst)
     */
    private function deleteRecursive(Category $category, EntityManagerInterface $em): void
    {
        // 1) Lade alle Kategorien und baue Kinder-Map
        $all = $em->getRepository(Category::class)->findAll();
        $map = [];
        foreach ($all as $c) {
            $map[$c->getId()] = [
                'entity' => $c,
                'children' => [],
                'parentId' => $c->getParent()?->getId(),
            ];
        }
        foreach ($map as $cid => $row) {
            $pid = $row['parentId'];
            if ($pid !== null && isset($map[$pid])) {
                $map[$pid]['children'][] = $cid;
            }
        }

        // 2) Sammle Post-Order Liste (Kinder vor Eltern)
        $order = [];
        $visit = function(int $cid) use (&$visit, &$order, $map) {
            foreach ($map[$cid]['children'] as $childId) {
                $visit($childId);
            }
            $order[] = $cid;
        };

        $rootId = $category->getId();
        if (!isset($map[$rootId])) {
            return;
        }
        $visit($rootId);

        if (empty($order)) {
            return;
        }

        // Defensive validation: nur gültige positive Integer-IDs behalten
        $ids = array_values(array_filter(array_map('intval', $order), fn($v) => $v > 0));
        if (empty($ids)) {
            return;
        }

        // 3) Bulk-Delete der Posts in Transaktion, in Chunks (sicher gegen große IN-Listen)
        $conn = $em->getConnection();
        $chunkSize = 1000; // anpassbar
        try {
            $em->beginTransaction();

            // Verwende DQL-Delete via QueryBuilder, aber binde Arrays sicher
            // Chunking: für sehr große ID-Listen
            $chunks = array_chunk($ids, $chunkSize);
            foreach ($chunks as $chunk) {
                // DQL -> QueryBuilder löscht sicher mit gebundenen Parametern
                $qb = $em->createQueryBuilder();
                $qb->delete(Post::class, 'p')
                ->where($qb->expr()->in('p.category', ':ids'))
                ->setParameter('ids', $chunk);
                $qb->getQuery()->execute();
            }

            // 4) Entferne Kategorien in Post-Order (Kinder zuerst)
            foreach ($order as $cid) {
                // referenzieren statt laden, spart Memory
                $ref = $em->getReference(Category::class, $cid);
                $em->remove($ref);
            }

            // 5) Einmal flush und Commit
            $em->flush();
            $em->commit();
        } catch (Throwable $e) {
            // Rollback bei Fehler und weiterwerfen oder loggen
            try {
                if ($em->getConnection()->isTransactionActive()) {
                    $em->rollback();
                }
            } catch (Throwable $inner) {
                // swallow secondary exceptions or log
            }

            throw $e instanceof ORMException ? $e : new \RuntimeException('Fehler beim Löschen der Kategorie', 0, $e);
        }
    }


    /**
     * Hilfsmethode zur Initialisierung von Lazy Collections
     * @param Category[] $categories
     */
    private function initializeCategoryChildren(array $categories): void
    {
        // ... (Methode bleibt unverändert, da sie keine OA-Annotations enthält) ...

        foreach ($categories as $category) {
            // Initialisiert die 'categories' (Kinder) Collection, falls noch nicht geladen
            $categoriesCollection = $category->getCategories();
            if ($categoriesCollection instanceof \Doctrine\ORM\PersistentCollection) {
                if (!$categoriesCollection->isInitialized()) {
                    $categoriesCollection->initialize();
                }
            } else {
                // Fallback: Erzwinge Initialisierung durch Iteration
                foreach ($categoriesCollection as $_) {
                    // Leere Schleife, nur zur Initialisierung
                }
            }
            
            // Rekursiv für alle Kinder
            $this->initializeCategoryChildren($categoriesCollection->toArray());
        }
    }
}
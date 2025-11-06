<?php
// src/Service/CategoryService.php

namespace App\Service;

use App\DTO\CategoryCreateDTO;
use App\DTO\CategoryUpdateDTO;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Erstellt eine neue Kategorie
     */
    public function createCategory(CategoryCreateDTO $dto): Category
    {
        $category = new Category();
        $category->setName($dto->name);

        if ($dto->parentId) {
            $parent = $this->em->getRepository(Category::class)->find($dto->parentId);
            if (!$parent) {
                throw new \InvalidArgumentException('Parent-Kategorie nicht gefunden');
            }
            $category->setParent($parent);
        }

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * Aktualisiert eine bestehende Kategorie
     */
    public function updateCategory(Category $category, CategoryUpdateDTO $dto): Category
    {
        $category->setName($dto->name);

        if ($dto->parentId !== null) {
            if ($dto->parentId === $category->getId()) {
                throw new \InvalidArgumentException('Eine Kategorie kann nicht ihr eigenes Parent sein');
            }

            $parent = $this->em->getRepository(Category::class)->find($dto->parentId);
            if (!$parent) {
                throw new \InvalidArgumentException('Parent-Kategorie nicht gefunden');
            }

            // Prüfe auf Zirkelbezug
            if ($this->hasCircularReference($category, $dto->parentId)) {
                throw new \InvalidArgumentException('Zirkelbezug erkannt: Parent-Kategorie kann keine Unterkategorie sein');
            }

            $category->setParent($parent);
        } else {
            $category->setParent(null);
        }

        $this->em->flush();

        return $category;
    }

    /**
     * Löscht eine Kategorie
     */
    public function deleteCategory(Category $category, bool $force = false): void
    {
        // Prüfe auf Unterkategorien
        if ($category->getCategories()->count() > 0 && !$force) {
            throw new \RuntimeException('Kategorie hat Unterkategorien. Verwende force=true zum Löschen.');
        }

        // Prüfe auf Posts
        if ($category->getPosts()->count() > 0 && !$force) {
            throw new \RuntimeException('Kategorie hat zugeordnete Posts. Verwende force=true zum Löschen.');
        }

        // Bei force: Posts und Unterkategorien behandeln
        if ($force) {
            foreach ($category->getPosts() as $post) {
                $this->em->remove($post);
            }

            foreach ($category->getCategories() as $subcategory) {
                $this->deleteRecursive($subcategory);
            }
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    /**
     * Prüft auf Zirkelbezüge in der Kategoriehierarchie
     */
    private function hasCircularReference(Category $category, int $targetParentId): bool
    {
        foreach ($category->getCategories() as $child) {
            if ($child->getId() === $targetParentId) {
                return true;
            }
            if ($this->hasCircularReference($child, $targetParentId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Löscht Kategorie rekursiv mit allen Unterkategorien und Posts
     */
    private function deleteRecursive(Category $category): void
    {
        foreach ($category->getPosts() as $post) {
            $this->em->remove($post);
        }

        foreach ($category->getCategories() as $subcategory) {
            $this->deleteRecursive($subcategory);
        }

        $this->em->remove($category);
    }

    /**
     * Gibt alle Root-Kategorien zurück (ohne Parent)
     */
    public function getRootCategories(): array
    {
        return $this->em->getRepository(Category::class)->findBy(['parent' => null]);
    }

    /**
     * Gibt die komplette Kategoriehierarchie als Array zurück
     */
    public function getCategoryTree(?Category $rootCategory = null): array
    {
        if ($rootCategory) {
            $categories = [$rootCategory];
        } else {
            $categories = $this->getRootCategories();
        }

        return array_map([$this, 'buildCategoryNode'], $categories);
    }

    /**
     * Baut einen Kategorie-Knoten für die Baumstruktur
     */
    private function buildCategoryNode(Category $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'parentId' => $category->getParent()?->getId(),
            'children' => array_map(
                [$this, 'buildCategoryNode'],
                $category->getCategories()->toArray()
            ),
            'postCount' => $category->getPosts()->count()
        ];
    }

    /**
     * Verschiebt alle Posts von einer Kategorie zu einer anderen
     */
    public function movePostsToCategory(Category $fromCategory, Category $toCategory): int
    {
        $count = 0;
        foreach ($fromCategory->getPosts() as $post) {
            $post->setCategory($toCategory);
            $count++;
        }
        
        $this->em->flush();
        
        return $count;
    }
}
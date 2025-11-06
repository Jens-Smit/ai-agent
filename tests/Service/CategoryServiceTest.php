<?php
// tests/Service/CategoryServiceTest.php

namespace App\Tests\Service;

use App\DTO\CategoryCreateDTO;
use App\DTO\CategoryUpdateDTO;
use App\Entity\Category;
use App\Entity\Post;
use App\Entity\User;
use App\Service\CategoryService;
use App\Repository\CategoryRepository; // NEU: Import des konkreten Repositorys
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @covers \App\Service\CategoryService
 */
class CategoryServiceTest extends TestCase
{
    private MockObject|EntityManagerInterface $em;
    // GEÄNDERT: Verwenden Sie CategoryRepository als Typ-Hint
    private MockObject|CategoryRepository $categoryRepo;
    private CategoryService $categoryService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        
        // GEÄNDERT: Mocken Sie das konkrete CategoryRepository
        $this->categoryRepo = $this->createMock(CategoryRepository::class);
        
        // Der EntityManager muss das konkrete Repository zurückgeben, um den
        // strengeren Typ-Hint in der neuen Doctrine-Version zu erfüllen.
        $this->em->method('getRepository')
            ->with(Category::class)
            ->willReturn($this->categoryRepo);
        
        $this->categoryService = new CategoryService($this->em);
    }

    public function testCreateCategorySuccessful(): void
    {
        $dto = new CategoryCreateDTO('Test Category');
        
        $persistedCategory = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (Category $category) use (&$persistedCategory) {
                $persistedCategory = $category;
            });
        
        $this->em->expects($this->once())->method('flush');

        $result = $this->categoryService->createCategory($dto);

        $this->assertNotNull($persistedCategory);
        $this->assertSame($persistedCategory, $result);
        $this->assertEquals('Test Category', $result->getName());
        $this->assertNull($result->getParent());
    }

    public function testCreateCategoryWithParent(): void
    {
        $parent = new Category();
        $parent->setName('Parent Category');
        
        $this->categoryRepo->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($parent);
        
        $dto = new CategoryCreateDTO('Child Category', 1);
        
        $persistedCategory = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (Category $category) use (&$persistedCategory) {
                $persistedCategory = $category;
            });
        
        $this->em->expects($this->once())->method('flush');

        $result = $this->categoryService->createCategory($dto);

        $this->assertNotNull($persistedCategory);
        $this->assertEquals('Child Category', $result->getName());
        $this->assertSame($parent, $result->getParent());
    }

    public function testCreateCategoryThrowsExceptionWhenParentNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parent-Kategorie nicht gefunden');

        $this->categoryRepo->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);
        
        $dto = new CategoryCreateDTO('Child Category', 999);
        
        $this->categoryService->createCategory($dto);
    }

    public function testUpdateCategorySuccessful(): void
    {
        $category = new Category();
        $category->setName('Old Name');
        
        $this->em->expects($this->once())->method('flush');

        $dto = new CategoryUpdateDTO(1, 'New Name');
        
        $result = $this->categoryService->updateCategory($category, $dto);

        $this->assertEquals('New Name', $result->getName());
    }

    public function testUpdateCategoryWithNewParent(): void
    {
        $category = new Category();
        $category->setName('Category');
        
        $newParent = new Category();
        $newParent->setName('New Parent');
        
        $this->categoryRepo->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($newParent);
        
        $this->em->expects($this->once())->method('flush');

        $dto = new CategoryUpdateDTO(1, 'Category', 5);
        
        $result = $this->categoryService->updateCategory($category, $dto);

        $this->assertSame($newParent, $result->getParent());
    }

    public function testUpdateCategoryRemovesParent(): void
    {
        $parent = new Category();
        $parent->setName('Parent');
        
        $category = new Category();
        $category->setName('Category');
        $category->setParent($parent);
        
        $this->em->expects($this->once())->method('flush');

        $dto = new CategoryUpdateDTO(1, 'Category', null);
        
        $result = $this->categoryService->updateCategory($category, $dto);

        $this->assertNull($result->getParent());
    }

    public function testUpdateCategoryThrowsExceptionForSelfReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Eine Kategorie kann nicht ihr eigenes Parent sein');

        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(5);
        
        $dto = new CategoryUpdateDTO(5, 'Category', 5);
        
        $this->categoryService->updateCategory($category, $dto);
    }

    public function testUpdateCategoryThrowsExceptionWhenParentNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parent-Kategorie nicht gefunden');

        $category = new Category();
        $category->setName('Category');
        
        $this->categoryRepo->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);
        
        $dto = new CategoryUpdateDTO(1, 'Category', 999);
        
        $this->categoryService->updateCategory($category, $dto);
    }

    public function testUpdateCategoryDetectsCircularReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Zirkelbezug erkannt');

        $grandparent = $this->createMock(Category::class);
        $grandparent->method('getId')->willReturn(1);
        
        $parent = $this->createMock(Category::class);
        $parent->method('getId')->willReturn(2);
        
        $child = $this->createMock(Category::class);
        $child->method('getId')->willReturn(3);
        $child->method('getCategories')->willReturn(new ArrayCollection());
        
        $parent->method('getCategories')->willReturn(new ArrayCollection([$child]));
        $grandparent->method('getCategories')->willReturn(new ArrayCollection([$parent]));
        
        $this->categoryRepo->expects($this->once())
            ->method('find')
            ->with(3)
            ->willReturn($child);
        
        $dto = new CategoryUpdateDTO(1, 'Grandparent', 3);
        
        $this->categoryService->updateCategory($grandparent, $dto);
    }

    public function testDeleteCategorySuccessful(): void
    {
        $category = $this->createMock(Category::class);
        // Mock the necessary methods to ensure the category is deletable
        $category->method('getCategories')->willReturn(new ArrayCollection());
        $category->method('getPosts')->willReturn(new ArrayCollection());

        // ASSERTION 1: Verify 'remove' is called on the EntityManager once
        $this->em->expects($this->once())
            ->method('remove')
            ->with($category);

        // ASSERTION 2: Verify 'flush' is called on the EntityManager once
        $this->em->expects($this->once())->method('flush');

        // ACT: Call the method under test
        $this->categoryService->deleteCategory($category);

        // Die impliziten Assertions auf Mocks reichen aus.
    }

    public function testDeleteCategoryThrowsExceptionWithSubcategories(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unterkategorien');

        $child = new Category();
        $child->setName('Child');
        
        $category = $this->createMock(Category::class);
        $category->method('getCategories')->willReturn(new ArrayCollection([$child]));
        $category->method('getPosts')->willReturn(new ArrayCollection());
        
        $this->categoryService->deleteCategory($category, false);
    }

    public function testDeleteCategoryThrowsExceptionWithPosts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Posts');

        $post = new Post();
        $post->setTitle('Test Post');
        
        $category = $this->createMock(Category::class);
        $category->method('getCategories')->willReturn(new ArrayCollection());
        $category->method('getPosts')->willReturn(new ArrayCollection([$post]));
        
        $this->categoryService->deleteCategory($category, false);
    }

    public function testDeleteCategoryWithForceDeletesPostsAndSubcategories(): void
    {
        $post = $this->createMock(Post::class);
        
        $grandchild = $this->createMock(Category::class);
        $grandchild->method('getCategories')->willReturn(new ArrayCollection());
        $grandchild->method('getPosts')->willReturn(new ArrayCollection());
        
        $child = $this->createMock(Category::class);
        $child->method('getCategories')->willReturn(new ArrayCollection([$grandchild]));
        $child->method('getPosts')->willReturn(new ArrayCollection());
        
        $category = $this->createMock(Category::class);
        $category->method('getCategories')->willReturn(new ArrayCollection([$child]));
        $category->method('getPosts')->willReturn(new ArrayCollection([$post]));
        
        // Erwarte remove für Post, Grandchild, Child und Category
        $this->em->expects($this->exactly(4))
            ->method('remove');
        
        $this->em->expects($this->once())->method('flush');

        $this->categoryService->deleteCategory($category, true);
    }

    public function testGetRootCategoriesReturnsOnlyCategoriesWithoutParent(): void
    {
        $root1 = new Category();
        $root1->setName('Root 1');
        
        $root2 = new Category();
        $root2->setName('Root 2');
        
        // Da CategoryRepository keine findBy-Methode hat, muss sie von ObjectRepository kommen.
        // Glücklicherweise implementiert CategoryRepository (normalerweise) ObjectRepository.
        $this->categoryRepo->expects($this->once())
            ->method('findBy')
            ->with(['parent' => null])
            ->willReturn([$root1, $root2]);
        
        $result = $this->categoryService->getRootCategories();

        $this->assertCount(2, $result);
        $this->assertSame($root1, $result[0]);
        $this->assertSame($root2, $result[1]);
    }

    public function testGetCategoryTreeBuildsHierarchicalStructure(): void
    {
        $grandchild = $this->createMock(Category::class);
        $grandchild->method('getId')->willReturn(3);
        $grandchild->method('getName')->willReturn('Grandchild');
        $grandchild->method('getParent')->willReturn(null);
        $grandchild->method('getCategories')->willReturn(new ArrayCollection());
        $grandchild->method('getPosts')->willReturn(new ArrayCollection());
        
        $child = $this->createMock(Category::class);
        $child->method('getId')->willReturn(2);
        $child->method('getName')->willReturn('Child');
        $child->method('getParent')->willReturn(null);
        $child->method('getCategories')->willReturn(new ArrayCollection([$grandchild]));
        $child->method('getPosts')->willReturn(new ArrayCollection());
        
        $parent = $this->createMock(Category::class);
        $parent->method('getId')->willReturn(1);
        $parent->method('getName')->willReturn('Parent');
        $parent->method('getParent')->willReturn(null);
        $parent->method('getCategories')->willReturn(new ArrayCollection([$child]));
        $parent->method('getPosts')->willReturn(new ArrayCollection());
        
        $result = $this->categoryService->getCategoryTree($parent);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Parent', $result[0]['name']);
        $this->assertArrayHasKey('children', $result[0]);
        $this->assertCount(1, $result[0]['children']);
        $this->assertEquals('Child', $result[0]['children'][0]['name']);
    }

    public function testGetCategoryTreeWithoutRootReturnsAllRootCategories(): void
    {
        $root1 = $this->createMock(Category::class);
        $root1->method('getId')->willReturn(1);
        $root1->method('getName')->willReturn('Root 1');
        $root1->method('getParent')->willReturn(null);
        $root1->method('getCategories')->willReturn(new ArrayCollection());
        $root1->method('getPosts')->willReturn(new ArrayCollection());
        
        $root2 = $this->createMock(Category::class);
        $root2->method('getId')->willReturn(2);
        $root2->method('getName')->willReturn('Root 2');
        $root2->method('getParent')->willReturn(null);
        $root2->method('getCategories')->willReturn(new ArrayCollection());
        $root2->method('getPosts')->willReturn(new ArrayCollection());
        
        $this->categoryRepo->expects($this->once())
            ->method('findBy')
            ->with(['parent' => null])
            ->willReturn([$root1, $root2]);
        
        $result = $this->categoryService->getCategoryTree();

        $this->assertCount(2, $result);
    }

    public function testMovePostsToCategorySuccessful(): void
    {
        $post1 = $this->createMock(Post::class);
        $post2 = $this->createMock(Post::class);
        
        $fromCategory = $this->createMock(Category::class);
        $fromCategory->method('getPosts')->willReturn(new ArrayCollection([$post1, $post2]));
        
        $toCategory = new Category();
        $toCategory->setName('Target Category');
        
        $post1->expects($this->once())
            ->method('setCategory')
            ->with($toCategory);
        
        $post2->expects($this->once())
            ->method('setCategory')
            ->with($toCategory);
        
        $this->em->expects($this->once())->method('flush');

        $count = $this->categoryService->movePostsToCategory($fromCategory, $toCategory);

        $this->assertEquals(2, $count);
    }

    public function testMovePostsToCategoryReturnsZeroWhenNoPosts(): void
    {
        $fromCategory = $this->createMock(Category::class);
        $fromCategory->method('getPosts')->willReturn(new ArrayCollection());
        
        $toCategory = new Category();
        
        $this->em->expects($this->once())->method('flush');

        $count = $this->categoryService->movePostsToCategory($fromCategory, $toCategory);

        $this->assertEquals(0, $count);
    }
}

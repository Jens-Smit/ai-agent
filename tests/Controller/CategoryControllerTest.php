<?php
// tests/Controller/CategoryControllerTest.php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\User;
use App\Entity\Post;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class CategoryControllerTest extends WebTestCase
{
    private $client;
    private $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Aufräumen in richtiger Reihenfolge
        $this->em->createQuery('DELETE FROM App\Entity\Post p')->execute();
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        $this->em->createQuery('DELETE FROM App\Entity\Category c')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }

    protected function tearDown(): void
    {
        if ($this->em && $this->em->isOpen()) {
            $this->em->close();
        }
        $this->em = null;
        $this->client = null;
        parent::tearDown();
    }

    private function createAndPersistUser(string $email = null): User
    {
        $email = $email ?? 'user_' . uniqid() . '@example.com';

        $user = new User();
        $user->setEmail($email);

        if (method_exists($user, 'setPassword')) {
            $user->setPassword('dummyhash');
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testIndexReturnsAllCategories(): void
    {
        $category1 = new Category();
        $category1->setName('Category 1');
        $this->em->persist($category1);

        $category2 = new Category();
        $category2->setName('Category 2');
        $this->em->persist($category2);

        $this->em->flush();

        $this->client->request('GET', '/api/categories');
        $this->assertResponseIsSuccessful();

        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertGreaterThanOrEqual(2, count($decoded));
    }

    public function testIndexFiltersRootCategories(): void
    {
        $parent = new Category();
        $parent->setName('Parent');
        $this->em->persist($parent);
        $this->em->flush();

        $child = new Category();
        $child->setName('Child');
        $child->setParent($parent);
        $this->em->persist($child);
        $this->em->flush();

        // Filter für Root-Kategorien (ohne Parent)
        $this->client->request('GET', '/api/categories?parentId=null');

        $this->assertResponseIsSuccessful();

        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded);
        
        // Prüfe, dass nur Parent-Kategorien zurückgegeben werden
        foreach ($decoded as $cat) {
            $this->assertNull($cat['parent'] ?? null);
        }
    }

    public function testIndexFiltersByParentId(): void
    {
        $parent = new Category();
        $parent->setName('Parent');
        $this->em->persist($parent);
        $this->em->flush();

        $child1 = new Category();
        $child1->setName('Child 1');
        $child1->setParent($parent);
        $this->em->persist($child1);

        $child2 = new Category();
        $child2->setName('Child 2');
        $child2->setParent($parent);
        $this->em->persist($child2);

        $this->em->flush();

        $this->client->request('GET', '/api/categories?parentId=' . $parent->getId());

        $this->assertResponseIsSuccessful();

        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(2, $decoded);
    }

    public function testGetCategoryByIdReturnsCategory(): void
    {
        $category = new Category();
        $category->setName('Test Category');
        $this->em->persist($category);
        $this->em->flush();

        $this->client->request('GET', '/api/categories/' . $category->getId());

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertEquals($category->getId(), $data['id']);
        $this->assertEquals('Test Category', $data['name']);
    }

    public function testGetCategoryByIdReturns404WhenNotFound(): void
    {
        $this->client->request('GET', '/api/categories/99999');

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testGetCategoryTreeReturnsHierarchy(): void
    {
        $parent = new Category();
        $parent->setName('Parent');
        $this->em->persist($parent);
        $this->em->flush();

        $child1 = new Category();
        $child1->setName('Child 1');
        $child1->setParent($parent);
        $this->em->persist($child1);

        $grandchild = new Category();
        $grandchild->setName('Grandchild');
        $grandchild->setParent($child1);
        $this->em->persist($grandchild);

        $this->em->flush();

        $this->client->request('GET', '/api/categories/' . $parent->getId() . '/tree');

        $this->assertResponseIsSuccessful();

        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        
        // Prüfe Hierarchie-Struktur
        $parentNode = $decoded[0];
        $this->assertArrayHasKey('children', $parentNode);
        $this->assertNotEmpty($parentNode['children']);
        
        $childNode = $parentNode['children'][0];
        $this->assertArrayHasKey('children', $childNode);
    }

    public function testGetCategoryTreeForRootReturnsAllRootCategories(): void
    {
        $root1 = new Category();
        $root1->setName('Root 1');
        $this->em->persist($root1);

        $root2 = new Category();
        $root2->setName('Root 2');
        $this->em->persist($root2);

        $this->em->flush();

        $this->client->request('GET', '/api/categories/root/tree');

        $this->assertResponseIsSuccessful();

        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(2, count($decoded));
    }

    public function testCreateCategorySuccess(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/categories',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'New Category'
            ])
        );

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('message', $data);

        // Prüfe, ob Kategorie wirklich erstellt wurde
        $this->em->clear();
        $category = $this->em->getRepository(Category::class)->find($data['id']);
        $this->assertNotNull($category);
        $this->assertEquals('New Category', $category->getName());
    }

    public function testCreateCategoryWithParent(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $parent = new Category();
        $parent->setName('Parent Category');
        $this->em->persist($parent);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/categories',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Child Category',
                'parentId' => $parent->getId()
            ])
        );

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->em->clear();
        $child = $this->em->getRepository(Category::class)->find($data['id']);
        $this->assertNotNull($child->getParent());
        $this->assertEquals($parent->getId(), $child->getParent()->getId());
    }

    public function testCreateCategoryFailsWithEmptyName(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/categories',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => ''])
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateCategoryFailsWithInvalidParent(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/categories',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Test',
                'parentId' => 99999
            ])
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateCategorySuccess(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $category = new Category();
        $category->setName('Old Name');
        $this->em->persist($category);
        $this->em->flush();

        $this->client->request(
            'PUT',
            '/api/categories/' . $category->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'New Name'])
        );

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->getRepository(Category::class)->find($category->getId());
        $this->assertEquals('New Name', $updated->getName());
    }

    public function testUpdateCategoryChangeParent(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $parent1 = new Category();
        $parent1->setName('Parent 1');
        $this->em->persist($parent1);

        $parent2 = new Category();
        $parent2->setName('Parent 2');
        $this->em->persist($parent2);

        $child = new Category();
        $child->setName('Child');
        $child->setParent($parent1);
        $this->em->persist($child);

        $this->em->flush();

        $this->client->request(
            'PUT',
            '/api/categories/' . $child->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Child',
                'parentId' => $parent2->getId()
            ])
        );

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->getRepository(Category::class)->find($child->getId());
        $this->assertEquals($parent2->getId(), $updated->getParent()->getId());
    }

    public function testUpdateCategoryPreventsCircularReference(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $parent = new Category();
        $parent->setName('Parent');
        $this->em->persist($parent);
        $this->em->flush();

        $child = new Category();
        $child->setName('Child');
        $child->setParent($parent);
        $this->em->persist($child);
        $this->em->flush();

        // Versuche, Parent als Kind von Child zu setzen
        $this->client->request(
            'PUT',
            '/api/categories/' . $parent->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Parent',
                'parentId' => $child->getId()
            ])
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Zirkelbezug', $data['error']);
    }

    public function testUpdateCategoryPreventsSelfReference(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $category = new Category();
        $category->setName('Category');
        $this->em->persist($category);
        $this->em->flush();

        $this->client->request(
            'PUT',
            '/api/categories/' . $category->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Category',
                'parentId' => $category->getId()
            ])
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteCategorySuccess(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $category = new Category();
        $category->setName('To Delete');
        $this->em->persist($category);
        $this->em->flush();

        $categoryId = $category->getId();

        $this->client->request('DELETE', '/api/categories/' . $categoryId);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $deleted = $this->em->getRepository(Category::class)->find($categoryId);
        $this->assertNull($deleted);
    }

    public function testDeleteCategoryFailsWithSubcategories(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $parent = new Category();
        $parent->setName('Parent');
        $this->em->persist($parent);
        $this->em->flush();

        $child = new Category();
        $child->setName('Child');
        $child->setParent($parent);
        $this->em->persist($child);
        $this->em->flush();

        $this->client->request('DELETE', '/api/categories/' . $parent->getId());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Unterkategorien', $data['error']);
    }

    public function testDeleteCategoryFailsWithPosts(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $category = new Category();
        $category->setName('Category');
        $this->em->persist($category);
        $this->em->flush();

        $post = new Post();
        $post->setTitle('Post');
        $post->setSlug('test-post');
        $post->setContent('Content');
        $post->setAuthor($user);
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();

        $this->client->request('DELETE', '/api/categories/' . $category->getId());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Posts', $data['error']);
    }

    public function testDeleteCategoryWithForceDeletesEverything(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $parent = new Category();
        $parent->setName('Parent');
        $this->em->persist($parent);
        $this->em->flush();

        $child = new Category();
        $child->setName('Child');
        $child->setParent($parent);
        $this->em->persist($child);

        $post = new Post();
        $post->setTitle('Post');
        $post->setContent('Content');
        $post->setSlug('test-post');
        $post->setAuthor($user);
        $post->setCategory($parent);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        
        $this->em->flush();

        $parentId = $parent->getId();
        $childId = $child->getId();
        $postId = $post->getId();

       // $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        
        $this->client->request('DELETE', '/api/categories/' . $parentId . '?force=true');

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        
        $this->assertNull($this->em->getRepository(Category::class)->find($parentId));
        $this->assertNull($this->em->getRepository(Category::class)->find($childId));
        $this->assertNull($this->em->getRepository(Post::class)->find($postId));
    }
}
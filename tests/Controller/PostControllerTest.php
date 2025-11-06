<?php
// tests/Controller/PostControllerTest.php

namespace App\Tests\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Entity\Category;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class PostControllerTest extends WebTestCase
{
    private $client;
    private $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Aufräumen: Posts, dann Categories, dann Users
        $this->em->createQuery('DELETE FROM App\Entity\Post p')->execute();
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');

        $this->em->createQuery('DELETE FROM App\Entity\Category c')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }
    private function createMockUploadedFile(string $filename = 'test.png', string $mimeType = 'image/png'): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        
        // ✅ Erstelle echte PNG-Datei (1x1 Pixel transparentes PNG)
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($tmpFile, $pngData);
        
        // Temporäre Datei merken für Cleanup
        $this->tmpFiles[] = $tmpFile;

        return new UploadedFile(
            $tmpFile,
            $filename,
            $mimeType,
            null,
            true  // test mode
        );
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

    private function createAndPersistCategory(string $name, ?Category $parent = null): Category
    {
        $category = new Category();
        $category->setName($name);
        
        if ($parent) {
            $category->setParent($parent);
        }

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    public function testIndexReturnsAllPosts(): void
    {
        $user = $this->createAndPersistUser();
        $category = $this->createAndPersistCategory('Test Category');

        $post1 = new Post();
        $post1->setTitle('Post A');
        $post1->setContent('Content A');
        $post1->setAuthor($user);
        $post1->setSlug('test-post_A');
        $post1->setCategory($category);
        $post1->setCreatedAt(new \DateTime());
        $this->em->persist($post1);

        $post2 = new Post();
        $post2->setTitle('Post B');
        $post2->setContent('Content B');
        $post2->setSlug('test-post-B');
        $post2->setAuthor($user);
        $post2->setCategory($category);
        $post2->setCreatedAt(new \DateTime());
        $this->em->persist($post2);

        $this->em->flush();

        $this->client->request('GET', '/api/posts');

        $this->assertResponseIsSuccessful();
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertGreaterThanOrEqual(2, count($decoded));
    }

    public function testIndexFiltersByCategoryId(): void
    {
        $user = $this->createAndPersistUser();
        $category1 = $this->createAndPersistCategory('Category 1');
        $category2 = $this->createAndPersistCategory('Category 2');

        $post1 = new Post();
        $post1->setTitle('Post in Category 1');
        $post1->setSlug('post-in-category-1');
        $post1->setContent('Content');
        $post1->setAuthor($user);
        $post1->setCategory($category1);
        $post1->setCreatedAt(new \DateTime());
        $this->em->persist($post1);

        $post2 = new Post();
        $post2->setTitle('Post in Category 2');
        $post2->setSlug('post-in-category-2');
        $post2->setContent('Content');
        $post2->setAuthor($user);
        $post2->setCategory($category2);
        $post2->setCreatedAt(new \DateTime());
        $this->em->persist($post2);

        $this->em->flush();

        // Filter nach Category 1
        $this->client->request('GET', '/api/posts?categoryId=' . $category1->getId());

        $this->assertResponseIsSuccessful();
        
        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertEquals('Post in Category 1', $decoded[0]['title']);
    }
    private function createRealImageFile(string $type = 'png'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_img_');
        
        if ($type === 'png') {
            // 1x1 Pixel transparentes PNG (kleinste gültige PNG-Datei)
            $imageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        } elseif ($type === 'jpg' || $type === 'jpeg') {
            // 1x1 Pixel JPEG
            $imageData = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A');
        } elseif ($type === 'gif') {
            // 1x1 Pixel GIF
            $imageData = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        } else {
            throw new \InvalidArgumentException("Unsupported image type: $type");
        }
        
        file_put_contents($tmp, $imageData);
        return $tmp;
    }
    public function testGetPostByIdReturnsPost(): void
    {
        $user = $this->createAndPersistUser();
        $category = $this->createAndPersistCategory('Test Category');

        $post = new Post();
        $post->setTitle('Single Post');
        $post->setSlug('single-post');
        $post->setContent('Single Content');
        $post->setAuthor($user);
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();
         // ✅ FIX: User einloggen
        $this->client->loginUser($user);
        $this->client->request('GET', '/api/posts/' . $post->getSlug());

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame($post->getId(), (int)$data['id']);
    }

    public function testGetPostByIdReturns404WhenNotFound(): void
    {
        $this->client->request('GET', '/api/posts/99999');

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreatePostSuccess(): void
    {
        $user = $this->createAndPersistUser();
        $category = $this->createAndPersistCategory('Test Category');
        
        $this->client->loginUser($user);

        $tmp = $this->createRealImageFile('png');
        $uploaded = new UploadedFile(
            $tmp,
            'title.png',
            'image/png',
            null,
            true
        );

        $parameters = [
            'title' => 'Neuer Test Post',
            'content' => 'Ein Inhalt für den Test',
            'slug' => 'neuer-test-post',
            'imageMap' => '{}',
            'categoryId' => $category->getId()
        ];

        $files = [
            'titleImage' => $uploaded
        ];

        $this->client->request(
            'POST',
            '/api/posts',
            $parameters,
            $files,
            ['CONTENT_TYPE' => 'multipart/form-data']
        );

        if (file_exists($tmp)) {
            @unlink($tmp);
        }

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertIsInt((int)$data['id']);

        // Prüfe, ob Post mit Kategorie gespeichert wurde
        $this->em->clear();
        $post = $this->em->getRepository(Post::class)->find($data['id']);
        $this->assertNotNull($post);
        $this->assertSame($category->getId(), $post->getCategory()->getId());
    }

    public function testCreatePostFailsWithMissingTitle(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/posts',
            ['content' => 'Inhalt ohne Titel'],
            [],
            ['CONTENT_TYPE' => 'multipart/form-data']
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testCreatePostFailsWithInvalidCategory(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, 'dummy');
        $uploaded = new UploadedFile($tmp, 'title.png', 'image/png', null, true);

        $this->client->request(
            'POST',
            '/api/posts',
            [
                'title' => 'Test Post',
                'content' => 'Content',
                'categoryId' => 99999 // Nicht existierende Kategorie
            ],
            ['titleImage' => $uploaded],
            ['CONTENT_TYPE' => 'multipart/form-data']
        );

        if (file_exists($tmp)) {
            @unlink($tmp);
        }

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdatePostSuccess(): void
    {
        $user = $this->createAndPersistUser();
        $category1 = $this->createAndPersistCategory('Category 1');
        $category2 = $this->createAndPersistCategory('Category 2');

        $post = new Post();
        $post->setTitle('Original Title');
        $post->setSlug('original-title'); 
        $post->setContent('Original Content');
        $post->setAuthor($user);
        $post->setCategory($category1);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/posts/' . $post->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Updated Title',
                'content' => 'Updated Content',
                'categoryId' => $category2->getId()
            ])
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);

        // Prüfe, ob Änderungen gespeichert wurden
        $this->em->clear();
        $updatedPost = $this->em->getRepository(Post::class)->find($post->getId());
        $this->assertEquals('Updated Title', $updatedPost->getTitle());
        $this->assertEquals('Updated Content', $updatedPost->getContent());
        $this->assertSame($category2->getId(), $updatedPost->getCategory()->getId());
    }

    public function testUpdatePostFailsWithMissingTitle(): void
    {
        $user = $this->createAndPersistUser();
        $category = $this->createAndPersistCategory('Test Category');

        $post = new Post();
        $post->setTitle('Original Title');
        $post->setSlug('original-title'); 
        $post->setContent('Original Content');
        $post->setAuthor($user);
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/posts/' . $post->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => '', // Leerer Titel
                'content' => 'New Content'
            ])
        );

        $this->assertSame(402, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdatePostFailsWhenNotAuthor(): void
    {
        $user1 = $this->createAndPersistUser('user1@test.com');
        $user2 = $this->createAndPersistUser('user2@test.com');
        $category = $this->createAndPersistCategory('Test Category');

        $post = new Post();
        $post->setTitle('Original Title');
        $post->setSlug('original-title');
        $post->setContent('Original Content');
        $post->setAuthor($user1);
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();

        // User2 versucht Post von User1 zu bearbeiten
        $this->client->loginUser($user2);

        $this->client->request(
            'POST',
            '/api/posts/' . $post->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Hacked Title',
                'content' => 'Hacked Content'
            ])
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdatePostReturns404WhenNotFound(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/posts/99999',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'New Title',
                'content' => 'New Content'
            ])
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDeletePostSuccess(): void
    {
        $user = $this->createAndPersistUser();
        $category = $this->createAndPersistCategory('Test Category');

        $post = new Post();
        $post->setTitle('Zu löschender Post');
        $post->setContent('...content...');
        $post->setAuthor($user);
        $post->setSlug('zu-loschender-post');
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();

        $postId = $post->getId();

        $this->client->loginUser($user);
        $this->client->request('DELETE', '/api/posts/' . $postId);

        $this->assertResponseIsSuccessful();

        $this->em->clear();

        $deleted = $this->em->getRepository(Post::class)->find($postId);
        $this->assertNull($deleted);
    }

    public function testDeletePostFailsWhenNotAuthor(): void
    {
        $user1 = $this->createAndPersistUser('user1@test.com');
        $user2 = $this->createAndPersistUser('user2@test.com');
        $category = $this->createAndPersistCategory('Test Category');

        $post = new Post();
        $post->setTitle('Post von User1');
        $post->setSlug('post-von-user1'); 
        $post->setContent('Content');
        $post->setAuthor($user1);
        $post->setCategory($category);
        $post->setCreatedAt(new \DateTime());
        $this->em->persist($post);
        $this->em->flush();

        // User2 versucht Post von User1 zu löschen
        $this->client->loginUser($user2);
        $this->client->request('DELETE', '/api/posts/' . $post->getId());

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testDeletePostReturns404WhenNotFound(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request('DELETE', '/api/posts/99999');

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadMediaSuccess(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $tmp = tempnam(sys_get_temp_dir(), 'media');
        file_put_contents($tmp, 'test media content');
        $uploaded = new UploadedFile(
            $tmp,
            'media.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/api/posts/upload',
            [],
            ['file' => $uploaded],
            ['CONTENT_TYPE' => 'multipart/form-data']
        );

        if (file_exists($tmp)) {
            @unlink($tmp);
        }

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('url', $data);
        $this->assertStringContainsString('/api/public/uploads/', $data['url']);
    }

    public function testUploadMediaFailsWithoutFile(): void
    {
        $user = $this->createAndPersistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/api/posts/upload',
            [],
            [],
            ['CONTENT_TYPE' => 'multipart/form-data']
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreatePostWithMultipleImages(): void
    {
        $user = $this->createAndPersistUser();
        $category = $this->createAndPersistCategory('Test Category');
        
        $this->client->loginUser($user);

        // ✅ FIX: Verwende createRealImageFile für ALLE Bilder
        $tmpTitle = $this->createRealImageFile('png');
        $titleImage = new UploadedFile($tmpTitle, 'title.png', 'image/png', null, true);

        $tmpImg1 = $this->createRealImageFile('jpeg');  // ✅ GEÄNDERT
        $image1 = new UploadedFile($tmpImg1, 'image1.jpg', 'image/jpeg', null, true);

        $tmpImg2 = $this->createRealImageFile('png');  // ✅ GEÄNDERT von GIF zu PNG
        $image2 = new UploadedFile($tmpImg2, 'image2.png', 'image/png', null, true);  // ✅ Extension geändert

        $parameters = [
            'title' => 'Post mit mehreren Bildern',
            'content' => 'Text mit [img1] und [img2]',
            'slug' => 'post-mit-mehreren-bildern',
            'imageMap' => json_encode([
                'img1' => 'image1.jpg',
                'img2' => 'image2.png'  // ✅ GEÄNDERT von .gif zu .png
            ]),
            'categoryId' => $category->getId()
        ];

        $files = [
            'titleImage' => $titleImage,
            'images' => [$image1, $image2]
        ];

        $this->client->request(
            'POST',
            '/api/posts',
            $parameters,
            $files,
            ['CONTENT_TYPE' => 'multipart/form-data']
        );

        // Cleanup
        foreach ([$tmpTitle, $tmpImg1, $tmpImg2] as $tmp) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);

        // Prüfe gespeicherte Daten
        $this->em->clear();
        $post = $this->em->getRepository(Post::class)->find($data['id']);
        $this->assertNotNull($post);
        
        // Content sollte keine Platzhalter mehr enthalten
        $this->assertStringNotContainsString('[img1]', $post->getContent());
        $this->assertStringNotContainsString('[img2]', $post->getContent());
        $this->assertStringContainsString('<img src=', $post->getContent());
        
        // Sollte mehrere Bilder haben
        $images = $post->getImages();
        $this->assertNotNull($images);
        $this->assertGreaterThanOrEqual(2, count($images));
    }
}
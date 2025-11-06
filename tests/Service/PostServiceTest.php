<?php
// tests/Service/PostServiceTest.php

namespace App\Tests\Service;

use App\DTO\PostCreateDTO;
use App\DTO\PostUpdateDTO;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\Category;
use App\Service\PostService;
use App\Repository\CategoryRepository; // NEU: Import des konkreten Repositorys
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
// use Doctrine\Persistence\ObjectRepository; // ENTFERNT, da das konkrete Repository gemockt wird

/**
 * @covers \App\Service\PostService
 */
class PostServiceTest extends TestCase
{
    private MockObject|EntityManagerInterface $em;
    private PostService $postService;
    private string $projectDir = '/var/www';
    private string $uploadDirectory;
    private string $apiUrl = 'https://api.example.com';
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        // Erstelle temporäres Upload-Verzeichnis
        $this->uploadDirectory = sys_get_temp_dir() . '/test_uploads_' . uniqid();
        mkdir($this->uploadDirectory);

        $this->em = $this->createMock(EntityManagerInterface::class);
        
        // Verwende echten AsciiSlugger statt Mock
        $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
        
        $this->postService = new PostService(
            $this->em,
            $this->projectDir,
            $this->uploadDirectory,
            $slugger,
            $this->apiUrl
        );
    }
    
    protected function tearDown(): void
    {
        // Lösche temporäre Dateien
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tmpFiles = [];

        // Lösche Upload-Verzeichnis
        if (is_dir($this->uploadDirectory)) {
            $files = glob($this->uploadDirectory . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->uploadDirectory);
        }
    }

    private function createMockUploadedFile(string $filename = 'test.png', string $content = null): UploadedFile
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        
        // ✅ FIX: Verwende echte Bild-Daten basierend auf Extension
        if ($content === null) {
            if ($extension === 'png') {
                // 1x1 Pixel transparentes PNG
                $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            } elseif (in_array($extension, ['jpg', 'jpeg'])) {
                // 1x1 Pixel JPEG
                $content = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A');
            } elseif ($extension === 'gif') {
                // 1x1 Pixel GIF
                $content = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            } else {
                $content = 'dummy image content';
            }
        }
        
        file_put_contents($tmpFile, $content);
        $this->tmpFiles[] = $tmpFile;

        // Bestimme MIME-Type basierend auf Extension
        $mimeType = match($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png'
        };

        return new UploadedFile(
            $tmpFile,
            $filename,
            $mimeType,
            null,
            true
        );
    }


    public function testCreatePostSuccessful(): void
    {
        $uploadedFile = $this->createMockUploadedFile('test_image.png');
        
        $persistedPost = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Post::class))
            ->willReturnCallback(function (Post $post) use (&$persistedPost) {
                $persistedPost = $post;
            });
        
        $this->em->expects($this->once())->method('flush');

        $dto = new PostCreateDTO(
            'Test Title',
            'Content with image [image-placeholder]',
            $uploadedFile,
            [],
            json_encode(['image-placeholder' => 'test_image.png'])
        );
        
        $author = new User();

        $this->postService->createPost($dto, $author);

        $this->assertNotNull($persistedPost);
        $this->assertInstanceOf(Post::class, $persistedPost);
        $this->assertEquals('Test Title', $persistedPost->getTitle());
        
        $titleImageFilename = $persistedPost->getTitleImage();
        $this->assertNotNull($titleImageFilename);
        $this->assertStringEndsWith('.png', $titleImageFilename);
        
        // Prüfe, ob Platzhalter ersetzt wurde
        $content = $persistedPost->getContent();
        $this->assertStringNotContainsString('[image-placeholder]', $content);
        $this->assertStringContainsString('<img src=', $content);
        $this->assertStringContainsString($this->apiUrl, $content);
    }

    public function testCreatePostWithCategory(): void
    {
        $uploadedFile = $this->createMockUploadedFile('test.png');
        
        $category = new Category();
        $category->setName('Test Category');
        
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($category);
        
        // ✅ FIX: Mock für Post-Repository mit EXAKTEM Slug
        $postRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $postRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['slug' => 'test-title'])  // ✅ GEÄNDERT: Exakter Slug statt $this->anything()
            ->willReturn(null);
        
        // ✅ FIX: Beide Repositories mocken
        $this->em->expects($this->exactly(2))
            ->method('getRepository')
            ->willReturnCallback(function($entityClass) use ($categoryRepo, $postRepo) {
                if ($entityClass === Category::class) {
                    return $categoryRepo;
                }
                if ($entityClass === Post::class) {
                    return $postRepo;
                }
                throw new \RuntimeException("Unexpected repository request for: $entityClass");
            });
        
        $persistedPost = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (Post $post) use (&$persistedPost) {
                $persistedPost = $post;
            });
        
        $this->em->expects($this->once())->method('flush');

        $dto = new PostCreateDTO(
            'Test Title',
            'Content',
            $uploadedFile,
            [],
            '{}',
            1 // categoryId
        );
        
        $author = new User();
        $this->postService->createPost($dto, $author);

        $this->assertNotNull($persistedPost);
        $this->assertSame($category, $persistedPost->getCategory());
    }

    public function testCreatePostThrowsExceptionWhenCategoryNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kategorie mit ID 999 nicht gefunden');

        $uploadedFile = $this->createMockUploadedFile('test.gif');
        
        // FIX: Mocken des konkreten CategoryRepository
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);
        
        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(Category::class)
            ->willReturn($categoryRepo);

        $dto = new PostCreateDTO(
            'Test Title',
            'Content',
            $uploadedFile,
            [],
            '{}',
            999
        );
        
        $author = new User();
        $this->postService->createPost($dto, $author);
    }

    public function testCreatePostThrowsExceptionWhenTitleImageIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Titelbild ist erforderlich');

        $dto = new PostCreateDTO(
            'Test Title',
            'Content',
            null,
            []
        );
        
        $author = new User();
        $this->postService->createPost($dto, $author);
    }

    public function testCreatePostWithMultipleImages(): void
    {
        $titleImage = $this->createMockUploadedFile('title.gif');
        $image1 = $this->createMockUploadedFile('image1.jpg');
        $image2 = $this->createMockUploadedFile('image2.png');
        
        $persistedPost = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (Post $post) use (&$persistedPost) {
                $persistedPost = $post;
            });
        
        $this->em->expects($this->once())->method('flush');

        $dto = new PostCreateDTO(
            'Test Title',
            'Content [img1] and [img2]',
            $titleImage,
            [$image1, $image2],
            json_encode([
                'img1' => 'image1.jpg',
                'img2' => 'image2.png'
            ])
        );
        
        $author = new User();
        $this->postService->createPost($dto, $author);

        $this->assertNotNull($persistedPost);
        $images = $persistedPost->getImages();
        $this->assertNotNull($images);
        $this->assertIsArray($images);
        $this->assertCount(3, $images); // title + 2 images
    }

    public function testUpdatePostSuccessful(): void
    {
        $post = new Post();
        $post->setTitle('Old Title');
        $post->setContent('Old Content');
        
        $this->em->expects($this->once())->method('flush');

        $dto = new PostUpdateDTO(
            1,
            'New Title',
            'New Content'
        );

        $updatedPost = $this->postService->updatePost($post, $dto);

        $this->assertEquals('New Title', $updatedPost->getTitle());
        $this->assertEquals('New Content', $updatedPost->getContent());
    }

    public function testUpdatePostWithCategory(): void
    {
        $post = new Post();
        $post->setTitle('Title');
        $post->setContent('Content');
        
        $category = new Category();
        $category->setName('New Category');
        
        // FIX: Mocken des konkreten CategoryRepository
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($category);
        
        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(Category::class)
            ->willReturn($categoryRepo);
        
        $this->em->expects($this->once())->method('flush');

        $dto = new PostUpdateDTO(
            1,
            'Updated Title',
            'Updated Content',
            5
        );

        $updatedPost = $this->postService->updatePost($post, $dto);

        $this->assertSame($category, $updatedPost->getCategory());
    }

    public function testUpdatePostThrowsExceptionWhenCategoryNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kategorie mit ID 999 nicht gefunden');

        $post = new Post();
        $post->setTitle('Title');
        
        // FIX: Mocken des konkreten CategoryRepository
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);
        
        $this->em->expects($this->once())
            ->method('getRepository')
            ->with(Category::class)
            ->willReturn($categoryRepo);

        $dto = new PostUpdateDTO(1, 'Title', 'Content', 999);
        
        $this->postService->updatePost($post, $dto);
    }

    public function testDeletePostRemovesFilesAndEntity(): void
    {
        // Erstelle echte Dateien im Upload-Verzeichnis
        $titleImage = 'title-123.gif';
        $image1 = 'image-456.jpg';
        
        file_put_contents($this->uploadDirectory . '/' . $titleImage, 'content');
        file_put_contents($this->uploadDirectory . '/' . $image1, 'content');
        
        $post = new Post();
        $post->setTitle('Test Post');
        $post->setTitleImage($titleImage);
        $post->setImages([$image1]);
        
        $this->em->expects($this->once())
            ->method('remove')
            ->with($post);
        
        $this->em->expects($this->once())->method('flush');

        $this->postService->deletePost($post);

        // Prüfe, ob Dateien gelöscht wurden
        $this->assertFileDoesNotExist($this->uploadDirectory . '/' . $titleImage);
        $this->assertFileDoesNotExist($this->uploadDirectory . '/' . $image1);
    }

    public function testUpdatePostOnlyUpdatesProvidedFields(): void
    {
        $post = new Post();
        $post->setTitle('Original Title');
        $post->setContent('Original Content');
        
        $this->em->expects($this->once())->method('flush');

        // Nur Titel aktualisieren
        $dto = new PostUpdateDTO(
            1,
            'New Title',
            null, // Content nicht aktualisieren
            null  // Category nicht aktualisieren
        );

        $updatedPost = $this->postService->updatePost($post, $dto);

        $this->assertEquals('New Title', $updatedPost->getTitle());
        $this->assertEquals('Original Content', $updatedPost->getContent());
    }
}

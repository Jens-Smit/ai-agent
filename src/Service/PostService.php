<?php
// src/Service/PostService.php

namespace App\Service;

use App\DTO\PostCreateDTO;
use App\DTO\PostUpdateDTO;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class PostService
{
    private EntityManagerInterface $em;
    private string $projectDir;
    private string $uploadDirectory;
    private SluggerInterface $slugger;
    private string $apiUrl;

    public function __construct(
        EntityManagerInterface $em,
        string $projectDir,
        string $uploadDirectory,
        SluggerInterface $slugger,
        string $apiUrl
    ) {
        $this->em = $em;
        $this->projectDir = $projectDir;
        $this->uploadDirectory = $uploadDirectory;
        $this->slugger = $slugger;
        $this->apiUrl = $apiUrl;
    }
    private function sanitizeContent(string $content): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,em,u,a[href],img[src|alt],h2,h3,ul,ol,li');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        
        $purifier = new \HTMLPurifier($config);
        return $purifier->purify($content);
    }
    public function createPost(PostCreateDTO $dto, User $author): Post
    {
        // Validierung: Titelbild ist erforderlich
        if (!$dto->titleImage instanceof UploadedFile) {
            throw new \InvalidArgumentException("Titelbild ist erforderlich.");
        }

        // Validierung: Kategorie muss existieren, wenn angegeben
        $category = null;
        if ($dto->categoryId !== null) {
            $category = $this->em->getRepository(Category::class)->find($dto->categoryId);
            if (!$category) {
                throw new \InvalidArgumentException("Kategorie mit ID {$dto->categoryId} nicht gefunden.");
            }
        }
        $slug = strtolower($this->slugger->slug($dto->title));
        $existing = $this->em->getRepository(Post::class)->findOneBy(['slug' => $slug]);
        if ($existing) {
            $slug .= '-' . uniqid();
        }
        $post = new Post();
        $post->setSlug($slug);
        $post->setTitle($dto->title);
        $post->setAuthor($author);
        $post->setCreatedAt(new \DateTime());
        
        if ($category) {
            $post->setCategory($category);
        }

        $uploadedFileMap = [];

        // Verarbeiten des Titelbilds
        $titleImageFilename = $this->uploadFile($dto->titleImage);
        $post->setTitleImage($titleImageFilename);
        $uploadedFileMap[$dto->titleImage->getClientOriginalName()] = $titleImageFilename;

        // Verarbeiten aller anderen Bilder
        foreach ($dto->images as $uploadedImage) {
            if ($uploadedImage instanceof UploadedFile) {
                $newFilename = $this->uploadFile($uploadedImage);
                $uploadedFileMap[$uploadedImage->getClientOriginalName()] = $newFilename;
            }
        }
        
        $finalContent = $this->sanitizeContent($dto->content ?? '');

        // Dekodieren der imageMap
        $imageMap = json_decode($dto->imageMap ?? '{}', true) ?? [];
                
        // Platzhalter im Content ersetzen
        foreach ($imageMap as $placeholderId => $originalFilename) {
            if (isset($uploadedFileMap[$originalFilename])) {
                $newFilename = $uploadedFileMap[$originalFilename];
                $placeholder = "[{$placeholderId}]";
                $mediaHtml = sprintf(
                    '<img src="%s/api/public/uploads/%s" alt="%s">',
                    $this->apiUrl,
                    $newFilename,
                    htmlspecialchars($originalFilename)
                );
                $finalContent = str_replace($placeholder, $mediaHtml, $finalContent);
            }
        }
                
        $post->setContent($finalContent);
        
        // Speichern aller hochgeladenen Bildpfade
        if (!empty($uploadedFileMap)) {
            $post->setImages(array_values($uploadedFileMap));
        }
        
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    public function updatePost(Post $post, PostUpdateDTO $dto): Post
    {
        // Aktualisiere Titel
        if ($dto->title !== null) {
            $post->setTitle($dto->title);
            $newSlug = strtolower($this->slugger->slug($dto->title));
            $post->setSlug($newSlug);
        }
        
        // Aktualisiere Content
        if ($dto->content !== null) {
            $post->setContent($dto->content);
        }
       
        // Aktualisiere Kategorie
        if ($dto->categoryId !== null) {
            $category = $this->em->getRepository(Category::class)->find($dto->categoryId);
            if (!$category) {
                throw new \InvalidArgumentException("Kategorie mit ID {$dto->categoryId} nicht gefunden.");
            }
            $post->setCategory($category);
        }

        $this->em->flush();

        return $post;
    }

    /**
     * Löscht einen Post und alle zugehörigen Dateien
     */
    public function deletePost(Post $post): void
    {
        // Lösche Titelbild
        if ($post->getTitleImage()) {
            $this->deleteFile($post->getTitleImage());
        }

        // Lösche alle anderen Bilder
        if ($post->getImages()) {
            foreach ($post->getImages() as $image) {
                $this->deleteFile($image);
            }
        }

        $this->em->remove($post);
        $this->em->flush();
    }

    /**
     * Lädt eine Datei hoch und gibt den neuen Dateinamen zurück
     */
    private function uploadFile(UploadedFile $file): string
    {
        // ✅ MIME-Type Whitelist
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \RuntimeException('Invalid file type');
        }
        
        // ✅ Größenlimit (5MB)
        if ($file->getSize() > 5242880) {
            throw new \RuntimeException('File too large');
        }
        
        // ✅ Validiere echten Content-Type (nicht nur Extension)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file->getRealPath());
        finfo_close($finfo);
        
        if ($realMime !== $file->getMimeType()) {
            throw new \RuntimeException('File content mismatch');
        }
        
        // ✅ Sichere Extension-Mapping
        $extension = match($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new \RuntimeException('Unsupported type')
        };
        
        $safeFilename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $newFilename = $safeFilename . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        
        $file->move($this->uploadDirectory, $newFilename);
        
        // ✅ Setze restriktive Permissions
        chmod($this->uploadDirectory . '/' . $newFilename, 0644);
        
        return $newFilename;
    }

    /**
     * Löscht eine Datei aus dem Upload-Verzeichnis
     */
    private function deleteFile(string $filename): void
    {
        // ✅ SICHER: Filename validieren
        if (preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
            throw new \InvalidArgumentException('Invalid filename');
        }
        
        $filePath = realpath($this->uploadDirectory . '/' . $filename);
        
        // Stelle sicher, dass Pfad im Upload-Dir liegt
        if ($filePath === false || 
            strpos($filePath, realpath($this->uploadDirectory)) !== 0) {
            throw new \RuntimeException('Invalid file path');
        }
        
        @unlink($filePath);
    }
}
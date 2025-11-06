<?php
namespace App\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;

// Entferne 'use Symfony\Component\HttpFoundation\File\UploadedFile;' hier
// da dieses DTO keine UploadedFile-Objekte mehr direkt verarbeitet.

class PostCreateDTO
{
    public function __construct(
         public readonly string $title,
        public readonly ?string $content,
        public readonly ?UploadedFile $titleImage,
        /** @var UploadedFile[] */
        public readonly array $images = [],  // Erwarte nun ein Array von URLs (Strings)
        public ?string $imageMap = null,
        public ?int $categoryId = null
    ) {}
}

<?php
// src/DTO/PostUpdateDTO.php

namespace App\DTO;


use Symfony\Component\Validator\Constraints as Assert;
class PostUpdateDTO
{
    public function __construct(
       public int $id,
        #[Assert\NotBlank]
        public string $title,
        public ?string $content, // Nullable, da get('content') null zurückgeben könnte
        public ?int $categoryId = null
        
    ) {}
}

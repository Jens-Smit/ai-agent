<?php
// src/DTO/CategoryUpdateDTO.php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CategoryUpdateDTO
{
    public function __construct(
        public readonly int $id,
        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 255)]
        public readonly string $name,
        public readonly ?int $parentId = null
    ) {}
}
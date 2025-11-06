<?php
// src/DTO/RegisterRequestDTO.php
namespace App\DTO;


class RegisterRequestDTO
{
    public function __construct(
        public readonly string $email,
        public readonly string $password
    ) {}
}
<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class AgentPromptRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(min: 10, max: 20000)] // Example length constraints
    public ?string $prompt = null;
}
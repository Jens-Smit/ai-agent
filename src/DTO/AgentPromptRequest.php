<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class AgentPromptRequest
{
    #[Assert\NotBlank]
    public string $prompt;
}
<?php
// src/DTO/AgentPromptRequest.php
namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "AgentPromptRequest",
    required: ["prompt"],
    description: "Request-Payload für AI Agent Prompts"
)]
class AgentPromptRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(min: 10, max: 20000)]
    #[OA\Property(
        property: "prompt",
        type: "string",
        description: "Der Benutzer-Prompt für den AI Agent",
        example: "Erzeuge ein Login-Formular im Frontend"
    )]
    public ?string $prompt = null;
}

<?php

namespace App\Message;

final class AiAgentJob
{
    public function __construct(
        public string $prompt,
        public ?string $originSessionId = null, // optional: zur Zuordnung von Status/Logs
        public array $options = [] // optional: future-proof (z.B. priority, userId, etc.)
    ) {}
}
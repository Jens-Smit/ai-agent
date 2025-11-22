<?php
// src/Message/FrontendGeneratorJob.php

namespace App\Message;

final class FrontendGeneratorJob
{
    public function __construct(
        public string $prompt,
        public string $sessionId, 
        public array $options = [], // 3. Argument
        public ?string $userId = null // 4. Argument
    ) {}
}
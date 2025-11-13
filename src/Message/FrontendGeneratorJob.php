<?php
// src/Message/FrontendGeneratorJob.php

namespace App\Message;

final class FrontendGeneratorJob
{
    public function __construct(
        public string $prompt,
        public string $sessionId,
        public ?string $userId = null,
        public array $options = []
    ) {}
}
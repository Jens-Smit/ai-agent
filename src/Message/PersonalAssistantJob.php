<?php
// src/Message/PersonalAssistantJob.php

namespace App\Message;

final class PersonalAssistantJob
{
    public function __construct(
        public string $prompt,
        public string $sessionId, // Für Status-Tracking
        public ?string $userId = null,
        public array $options = []
    ) {}
}
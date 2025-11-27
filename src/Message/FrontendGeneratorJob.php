<?php

declare(strict_types=1);

namespace App\Message;

final class FrontendGeneratorJob
{
    public function __construct(
        public string $prompt,
        public string $sessionId,
        public array $options = [],
        public ?string $originSessionId = null,
        public ?string $userId = null
    ) {}
}
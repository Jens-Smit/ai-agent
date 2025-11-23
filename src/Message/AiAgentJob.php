<?php

namespace App\Message;

final class AiAgentJob
{
    public function __construct(
        public string $prompt,
        public ?string $originSessionId = null, // optional: zur Zuordnung von Status/Logs
        public array $options = [], // optional: future-proof (z.B. priority, userId, etc.)
        // NEU: Tracking für Tool-Entwicklung
        public ?string $targetToolName = null,      // z.B. "CoverLetterGeneratorTool"
        public array $previousErrors = [],          // Fehler aus vorherigen Versuchen
        public int $toolCreationAttempt = 0,         // Wie oft wurde versucht, DIESES Tool zu erstellen
       
    ) {}
}
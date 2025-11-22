<?php 
// src/MessageHandler/FrontendGeneratorJobHandler.php

namespace App\MessageHandler;

use App\Message\FrontendGeneratorJob;
use App\Service\FrontendAgentService; // NEU: Den dedizierten Service importieren
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FrontendGeneratorJobHandler
{
    public function __construct(
        // NEU: Den dedizierten FrontendAgentService injizieren
        private FrontendAgentService $frontendAgentService, 
        private LoggerInterface $logger
    ) {}

    public function __invoke(FrontendGeneratorJob $job): void
    {
        // Annahme: Die options enthalten den attempt-Wert, falls es ein Retry ist.
        $attempt = $job->options['attempt'] ?? 1;
        
        // KORREKTUR: Zugriff auf $originSessionId sicherer gestalten. 
        // Wir verwenden \is_initialized(), um den Fehler "must not be accessed before initialization" zu verhindern.
        $originSessionId = null;
        if (property_exists($job, 'originSessionId') && \is_initialized($job, 'originSessionId')) {
            $originSessionId = $job->originSessionId;
        }

        $this->logger->info('FrontendGeneratorJobHandler: Job empfangen.', [
            'prompt' => $job->prompt,
            'sessionId' => $job->sessionId, // Die aktuelle Job ID
            // KORREKTUR VOM ZUGRIFF: Zugriff durch die Variable $originSessionId ersetzen.
            'originSessionId' => $originSessionId,
            'options' => $job->options,
            'attempt' => $attempt
        ]);

        try {
            // AUFRUF GEÄNDERT:
            // 1. Verwende den neuen Service $this->frontendAgentService.
            // 2. Übergibt $job->sessionId als aktuelle Session ID.
            // 3. Übergibt den aktuellen attempt-Wert (aus Optionen).
            $this->frontendAgentService->runPrompt(
                $job->prompt, 
                $job->sessionId, // KORREKT: sessionId ist die aktuelle ID dieses Jobs
                $attempt
            );

            $this->logger->info('FrontendGeneratorJobHandler: Job erfolgreich abgeschlossen.');

        } catch (\Throwable $e) {
            // WICHTIG: Wenn dies ein nicht behebbarer Fehler ist (wie das Rate Limit), 
            // wird die Logik im FrontendAgentService die Wiederholung (Retry) oder den Fehler behandeln.
            $this->logger->error('FrontendGeneratorJobHandler: Fehler beim Ausführen.', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'sessionId' => $job->sessionId,
                // Keine Trace-Ausgabe hier, da dies zu viel Protokollierung verursacht;
                // Der AgentService sollte die Trace-Logik übernehmen.
            ]);
            
            // Re-Throwing ist hier nicht nötig, da der AgentService sich um das Requeuing kümmert.
            // Wenn der Service selbst einen Fehler wirft, wird Messenger ihn fangen.
            throw $e; 
        }
    }
}
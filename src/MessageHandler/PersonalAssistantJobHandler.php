<?php
// src/MessageHandler/PersonalAssistantJobHandler.php

namespace App\MessageHandler;

use App\Message\PersonalAssistantJob;
use App\Service\AgentStatusService;
use App\Service\GoogleClientService;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsMessageHandler]
final class PersonalAssistantJobHandler
{
    private const MAX_RETRIES = 5;
    private const RETRY_DELAY_SECONDS = 60;

    public function __construct(
        #[Autowire(service: 'ai.agent.personal_assistent')]
        private AgentInterface $agent,
        private AgentStatusService $agentStatusService,
        private LoggerInterface $logger,
        private UserRepository $userRepository,
        private GoogleClientService $googleClientService
    ) {}

    public function __invoke(PersonalAssistantJob $job): void
    {
        $this->logger->info('PersonalAssistantJobHandler: Job empfangen.', [
            'prompt' => $job->prompt,
            'sessionId' => $job->sessionId
        ]);

        $this->agentStatusService->clearStatuses($job->sessionId);
        $this->agentStatusService->addStatus($job->sessionId, 'Personal Assistant Job gestartet');

        // User laden
        $user = $this->userRepository->find($job->userId);
        if (!$user) {
            $this->agentStatusService->addStatus($job->sessionId, 'ERROR: User nicht gefunden');
            return;
        }

        // Google Token prüfen
        if (empty($user->getGoogleAccessToken())) {
            $this->agentStatusService->addStatus(
                $job->sessionId,
                'RESULT:Google Calendar nicht verbunden. Bitte /connect/google aufrufen.'
            );
            return;
        }

        // Google Client initialisieren
        try {
            $googleClient = $this->googleClientService->getClientForUser($user);
        } catch (\Throwable $e) {
            $this->agentStatusService->addStatus(
                $job->sessionId,
                'ERROR: Google Client konnte nicht initialisiert werden: ' . $e->getMessage()
            );
            return;
        }

        // Erstelle enriched Message mit User-Kontext
        $messages = new MessageBag(
            Message::ofUser($job->prompt)
        );

        // Setze User-ID im Tool-Kontext (falls Tools den User brauchen)
        // Dies funktioniert, weil alle Tools im selben Request-Scope laufen
        $GLOBALS['current_user_id'] = $user->getId();

        $attempt = 1;
        $result = null;

        while ($attempt <= self::MAX_RETRIES) {
            try {
                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    sprintf('AI-Agent wird aufgerufen (Versuch %d/%d)', $attempt, self::MAX_RETRIES)
                );

                // FIX: Entferne die unzulässigen Parameter
                $result = $this->agent->call($messages);

                $this->agentStatusService->addStatus($job->sessionId, 'Antwort vom AI-Agent erhalten');
                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    'RESULT:' . $result->getContent()
                );

                $this->logger->info('PersonalAssistantJobHandler: Job erfolgreich abgeschlossen.');
                break;

            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                $isRetriable = $e instanceof ServerExceptionInterface ||
                               $e instanceof TransportExceptionInterface ||
                               str_contains($errorMessage, '503') ||
                               str_contains($errorMessage, 'UNAVAILABLE') ||
                               str_contains($errorMessage, 'overloaded') ||
                               str_contains($errorMessage, 'Response does not contain any content.');

                if ($isRetriable && $attempt < self::MAX_RETRIES) {
                    $this->logger->warning('Retriable Fehler erkannt. Warte und versuche erneut.', [
                        'attempt' => $attempt,
                        'error' => $errorMessage
                    ]);

                    $this->agentStatusService->addStatus(
                        $job->sessionId,
                        sprintf('Fehler (Retriable). Warte %d Sek.', self::RETRY_DELAY_SECONDS)
                    );

                    sleep(self::RETRY_DELAY_SECONDS);
                    $attempt++;
                    continue;
                }

                $this->logger->error('PersonalAssistantJobHandler: Unhandled exception.', [
                    'exception' => $errorMessage,
                    'attempt' => $attempt
                ]);

                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    'ERROR:' . $errorMessage
                );
                return;
            }
        }

        if ($result === null) {
            $this->agentStatusService->addStatus(
                $job->sessionId,
                sprintf('ERROR: Alle %d Versuche fehlgeschlagen', self::MAX_RETRIES)
            );
        }

        // Cleanup: Entferne User-Kontext
        unset($GLOBALS['current_user_id']);
    }
}
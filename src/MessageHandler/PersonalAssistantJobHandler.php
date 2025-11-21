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
        private GoogleClientService $googleClientService,
    ) {}

    public function __invoke(PersonalAssistantJob $job): void
    {
        $this->logger->info('🚀 PersonalAssistantJobHandler: Job empfangen', [
            'prompt' => substr($job->prompt, 0, 200),
            'sessionId' => $job->sessionId,
            'userId' => $job->userId
        ]);

        $this->agentStatusService->clearStatuses($job->sessionId);
        $this->agentStatusService->addStatus($job->sessionId, '🚀 Personal Assistant Job gestartet');

        // User validation
        $user = $this->userRepository->find($job->userId);
        if (!$user) {
            $this->logger->error('❌ User nicht gefunden', ['userId' => $job->userId]);
            $this->agentStatusService->addStatus($job->sessionId, 'ERROR: User nicht gefunden');
            return;
        }

        $this->logger->info('✅ User gefunden', [
            'userId' => $user->getId(),
            'email' => $user->getEmail()
        ]);

        // Google Token check
        if (empty($user->getGoogleAccessToken())) {
            $this->logger->warning('⚠️ Google nicht verbunden', ['userId' => $user->getId()]);
            $this->agentStatusService->addStatus(
                $job->sessionId,
                'RESULT: Google Calendar nicht verbunden. Bitte /connect/google aufrufen.'
            );
            return;
        }

        // Google Client initialization
        try {
            $this->logger->info('🔐 Initialisiere Google Client');
            $googleClient = $this->googleClientService->getClientForUser($user);
            $this->logger->info('✅ Google Client erfolgreich initialisiert');
        } catch (\Throwable $e) {
            $this->logger->error('❌ Google Client Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->agentStatusService->addStatus(
                $job->sessionId,
                'ERROR: Google Client konnte nicht initialisiert werden: ' . $e->getMessage()
            );
            return;
        }

        // Prepare message
        $messages = new MessageBag(Message::ofUser($job->prompt));
        
        $this->logger->info('📝 MessageBag erstellt', [
            'message_count' => count($messages->getMessages())
        ]);

        // Set user context globally for tools
        $GLOBALS['current_user_id'] = $user->getId();
        $this->logger->debug('🌐 User context global gesetzt', [
            'user_id' => $GLOBALS['current_user_id']
        ]);

        // Retry loop
        $attempt = 1;
        $result = null;

        while ($attempt <= self::MAX_RETRIES) {
            try {
                $this->logger->info("🤖 AI-Agent Aufruf (Versuch {$attempt}/" . self::MAX_RETRIES . ")", [
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                    'session' => $job->sessionId
                ]);

                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    sprintf('🤖 AI-Agent wird aufgerufen (Versuch %d/%d)', $attempt, self::MAX_RETRIES)
                );

                // THE ACTUAL AGENT CALL
                $this->logger->debug('📤 Sende Request an Gemini API');
                $result = $this->agent->call($messages);
                $this->logger->info('📥 Response von Gemini API erhalten');

                $this->agentStatusService->addStatus($job->sessionId, '✅ Antwort vom AI-Agent erhalten');
                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    'RESULT:' . $result->getContent()
                );

                $this->logger->info('✅ PersonalAssistantJobHandler: Job erfolgreich abgeschlossen', [
                    'session' => $job->sessionId,
                    'result_preview' => substr($result->getContent(), 0, 200)
                ]);
                
                break; // Success - exit retry loop

            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage() ?? get_class($e);
                
                $this->logger->error("❌ Fehler bei Versuch {$attempt}", [
                    'attempt' => $attempt,
                    'error_class' => get_class($e),
                    'error_message' => $errorMessage,
                    'error_code' => method_exists($e, 'getCode') ? $e->getCode() : 'N/A',
                    'trace' => $e->getTraceAsString()
                ]);

                // Check if error is retriable
                $isRetriable = $e instanceof ServerExceptionInterface ||
                               $e instanceof TransportExceptionInterface ||
                               str_contains($errorMessage, '503') ||
                               str_contains($errorMessage, 'UNAVAILABLE') ||
                               str_contains($errorMessage, 'overloaded') ||
                               str_contains($errorMessage, 'Response does not contain any content.');

                $this->logger->info('🔍 Fehleranalyse', [
                    'is_retriable' => $isRetriable,
                    'is_server_exception' => $e instanceof ServerExceptionInterface,
                    'is_transport_exception' => $e instanceof TransportExceptionInterface,
                    'contains_503' => str_contains($errorMessage, '503'),
                    'contains_unavailable' => str_contains($errorMessage, 'UNAVAILABLE')
                ]);

                // Check if we should retry
                if ($isRetriable && $attempt < self::MAX_RETRIES) {
                    $this->logger->warning('⚠️ Retriable Fehler erkannt - warte und versuche erneut', [
                        'attempt' => $attempt,
                        'next_attempt' => $attempt + 1,
                        'wait_seconds' => self::RETRY_DELAY_SECONDS
                    ]);

                    $this->agentStatusService->addStatus(
                        $job->sessionId,
                        sprintf('⚠️ Vorübergehender Fehler: %s', substr($errorMessage, 0, 140))
                    );
                    $this->agentStatusService->addStatus(
                        $job->sessionId,
                        sprintf('⏱️ Neuer Versuch in %ds (Attempt %d/%d)', 
                            self::RETRY_DELAY_SECONDS, 
                            $attempt + 1, 
                            self::MAX_RETRIES
                        )
                    );

                    sleep(self::RETRY_DELAY_SECONDS);
                    $attempt++;
                    continue;
                }

                // Non-retriable or max retries reached
                $this->logger->error('💀 Unhandled exception - Abbruch', [
                    'exception' => $errorMessage,
                    'attempt' => $attempt,
                    'is_retriable' => $isRetriable,
                    'max_retries_reached' => $attempt >= self::MAX_RETRIES
                ]);

                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    'ERROR:' . substr($errorMessage, 0, 300)
                );
                
                unset($GLOBALS['current_user_id']);
                return;
            }
        }

        // Check if all retries failed
        if ($result === null) {
            $this->logger->error('💀 Alle Retry-Versuche fehlgeschlagen', [
                'session' => $job->sessionId,
                'total_attempts' => self::MAX_RETRIES
            ]);
            
            $this->agentStatusService->addStatus(
                $job->sessionId,
                sprintf('ERROR: Alle %d Versuche fehlgeschlagen', self::MAX_RETRIES)
            );
        }

        unset($GLOBALS['current_user_id']);
        $this->logger->info('🧹 Cleanup abgeschlossen - User context entfernt');
    }
}
<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class AiAgentRetryExecutor
{
    private const MAX_RETRIES = 25; // Angepasst an den AiAgentController
    private const RETRY_DELAY_SECONDS = 60; // Angepasst an den AiAgentController

    public function __construct(
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService
    ) {}

    /**
     * Executes an AI agent call with retry logic.
     *
     * @param AgentInterface $agent The AI agent to call.
     * @param MessageBag $messages The message bag for the agent.
     * @param string $agentName A name to identify the agent in logs/status messages.
     * @return mixed The result from the AI agent.
     * @throws \RuntimeException If all retry attempts fail.
     */
    public function execute(AgentInterface $agent, MessageBag $messages, string $agentName = 'AI Agent'): mixed
    {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= self::MAX_RETRIES) {
            try {
                $this->logger->info(sprintf('Starting %s call (Attempt %d/%d)', $agentName, $attempt, self::MAX_RETRIES));
                $this->agentStatusService->addStatus(sprintf('Prompt an %s gesendet (Versuch %d/%d).', $agentName, $attempt, self::MAX_RETRIES));

                $result = $agent->call($messages);
                $this->agentStatusService->addStatus(sprintf('Antwort vom %s erhalten.', $agentName));
                $this->logger->info(sprintf('%s call successful.', $agentName), ['attempt' => $attempt]);
                return $result; // Exit loop on success
            } catch (ServerExceptionInterface $e) {
                $lastException = $e;
                $this->logger->error(sprintf('%s call failed (Attempt %d/%d): %s', $agentName, $attempt, self::MAX_RETRIES, $e->getMessage()), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Fehler beim %s-Aufruf (Versuch %d/%d): %s', $agentName, $attempt, self::MAX_RETRIES, $e->getMessage()));

                if ($attempt < self::MAX_RETRIES) {
                    $this->logger->warning(sprintf('Retrying %s call in %d seconds...', $agentName, self::RETRY_DELAY_SECONDS));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', self::RETRY_DELAY_SECONDS));
                    sleep(self::RETRY_DELAY_SECONDS);
                }
            } catch (TransportExceptionInterface $e) {
                $lastException = $e;
                $this->logger->error(sprintf('%s call failed (Attempt %d/%d) due to transport error: %s', $agentName, $attempt, self::MAX_RETRIES, $e->getMessage()), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Transportfehler beim %s-Aufruf (Versuch %d/%d): %s', $agentName, $attempt, self::MAX_RETRIES, $e->getMessage()));

                if ($attempt < self::MAX_RETRIES) {
                    $this->logger->warning(sprintf('Retrying %s call in %d seconds...', $agentName, self::RETRY_DELAY_SECONDS));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', self::RETRY_DELAY_SECONDS));
                    sleep(self::RETRY_DELAY_SECONDS);
                }
            }
            catch (\Throwable $e) { // Catch all other throwables
                $lastException = $e;
                $this->logger->error(sprintf('An unexpected error (Throwable) occurred during %s call (Attempt %d/%d): %s', $agentName, $attempt, self::MAX_RETRIES, $e->getMessage()), [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'attempt' => $attempt
                ]);
                $this->agentStatusService->addStatus(sprintf('Unerwarteter Fehler beim %s-Aufruf (Versuch %d/%d): %s', $agentName, $attempt, self::MAX_RETRIES, $e->getMessage()));
                if ($attempt < self::MAX_RETRIES) {
                    $this->logger->warning(sprintf('Retrying %s call in %d seconds...', $agentName, self::RETRY_DELAY_SECONDS));
                    $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', self::RETRY_DELAY_SECONDS));
                    sleep(self::RETRY_DELAY_SECONDS);
                }
            }
            $attempt++;
        }

        $this->logger->critical(sprintf('All %s call attempts failed after retries.', $agentName), ['last_exception' => $lastException ? $lastException->getMessage() : 'N/A']);
        $this->agentStatusService->addStatus(sprintf('Kritischer Fehler: %s nach mehreren Versuchen nicht verfügbar.', $agentName));
        throw new \RuntimeException('AI Agent is currently unavailable after multiple retries.', 0, $lastException);
    }
}

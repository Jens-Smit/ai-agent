<?php
// src/EventSubscriber/TokenTrackingSubscriber.php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\TokenTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * HTTP Client Decorator der Token-Usage tracked
 * Fängt Requests an AI APIs ab und extrahiert Token-Counts
 */
class TokenTrackingHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    private array $aiApiPatterns = [
        'generativelanguage.googleapis.com' => 'gemini',
        'api.openai.com' => 'openai',
        'api.anthropic.com' => 'anthropic',
    ];

    public function __construct(
        private HttpClientInterface $client,
        private TokenTrackingService $tokenService,
        private LoggerInterface $logger,
        private EntityManagerInterface $em
    ) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);
        
        // Prüfe ob es ein AI API Call ist
        $aiProvider = $this->detectAiProvider($url);
        
        if (!$aiProvider) {
            // Normaler Request - kein Tracking
            return $this->client->request($method, $url, $options);
        }

        try {
            $response = $this->client->request($method, $url, $options);
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            // Extrahiere Token-Counts aus Response
            $this->trackTokensFromResponse($response, $aiProvider, $responseTime, $options);

            return $response;

        } catch (\Throwable $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            
            $this->logger->error('AI API request failed', [
                'provider' => $aiProvider,
                'error' => $e->getMessage(),
                'url' => $url
            ]);

            // Track auch fehlgeschlagene Requests
            $this->trackFailedRequest($aiProvider, $responseTime, $e->getMessage(), $options);

            throw $e;
        }
    }

    private function detectAiProvider(string $url): ?string
    {
        foreach ($this->aiApiPatterns as $pattern => $provider) {
            if (str_contains($url, $pattern)) {
                return $provider;
            }
        }
        return null;
    }

    private function trackTokensFromResponse(
        ResponseInterface $response,
        string $provider,
        int $responseTime,
        array $requestOptions
    ): void {
        try {
            $content = $response->getContent(false);
            $data = json_decode($content, true);

            if (!$data) {
                return;
            }

            $tokenData = $this->extractTokenData($data, $provider);
            
            if (!$tokenData) {
                return;
            }

            // Hole User aus Context (muss vorher gesetzt werden)
            $userId = $GLOBALS['current_user_id'] ?? null;
            if (!$userId) {
                $this->logger->debug('No user context for token tracking');
                return;
            }

            $user = $this->em->find(\App\Entity\User::class, $userId);
            if (!$user) {
                return;
            }

            // Extrahiere Session-ID aus Request-Body falls vorhanden
            $sessionId = null;
            if (isset($requestOptions['json']['messages'])) {
                // Suche nach Session-ID in Message-Context
                $sessionId = $this->extractSessionIdFromMessages($requestOptions['json']['messages']);
            }

            // Extrahiere Modellname
            $model = $this->extractModelName($data, $provider, $requestOptions);

            // Extrahiere Agent-Type (aus Request oder Context)
            $agentType = $GLOBALS['current_agent_type'] ?? 'unknown';

            // Request-Preview
            $requestPreview = $this->createRequestPreview($requestOptions);

            // Track Token Usage
            $this->tokenService->trackTokenUsage(
                user: $user,
                model: $model,
                agentType: $agentType,
                inputTokens: $tokenData['input_tokens'],
                outputTokens: $tokenData['output_tokens'],
                sessionId: $sessionId,
                requestPreview: $requestPreview,
                responseTimeMs: $responseTime,
                success: true
            );

        } catch (\Throwable $e) {
            $this->logger->error('Failed to track token usage', [
                'error' => $e->getMessage(),
                'provider' => $provider
            ]);
        }
    }

    private function extractTokenData(array $data, string $provider): ?array
    {
        return match($provider) {
            'gemini' => $this->extractGeminiTokens($data),
            'openai' => $this->extractOpenAiTokens($data),
            'anthropic' => $this->extractAnthropicTokens($data),
            default => null
        };
    }

    private function extractGeminiTokens(array $data): ?array
    {
        // Gemini Response Format:
        // {
        //   "usageMetadata": {
        //     "promptTokenCount": 123,
        //     "candidatesTokenCount": 456,
        //     "totalTokenCount": 579
        //   }
        // }
        
        $metadata = $data['usageMetadata'] ?? null;
        if (!$metadata) {
            return null;
        }

        return [
            'input_tokens' => $metadata['promptTokenCount'] ?? 0,
            'output_tokens' => $metadata['candidatesTokenCount'] ?? 0,
        ];
    }

    private function extractOpenAiTokens(array $data): ?array
    {
        // OpenAI Response Format:
        // {
        //   "usage": {
        //     "prompt_tokens": 123,
        //     "completion_tokens": 456,
        //     "total_tokens": 579
        //   }
        // }
        
        $usage = $data['usage'] ?? null;
        if (!$usage) {
            return null;
        }

        return [
            'input_tokens' => $usage['prompt_tokens'] ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
        ];
    }

    private function extractAnthropicTokens(array $data): ?array
    {
        // Anthropic Response Format:
        // {
        //   "usage": {
        //     "input_tokens": 123,
        //     "output_tokens": 456
        //   }
        // }
        
        $usage = $data['usage'] ?? null;
        if (!$usage) {
            return null;
        }

        return [
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ];
    }

    private function extractModelName(array $data, string $provider, array $requestOptions): string
    {
        // Versuche Modellname aus verschiedenen Quellen zu extrahieren
        
        // 1. Aus Request-URL
        if (isset($requestOptions['url'])) {
            if (preg_match('/models\/([^:\/]+)/', $requestOptions['url'], $matches)) {
                return $matches[1];
            }
        }

        // 2. Aus Request-Body
        if (isset($requestOptions['json']['model'])) {
            return $requestOptions['json']['model'];
        }

        // 3. Aus Response
        if (isset($data['model'])) {
            return $data['model'];
        }

        // 4. Fallback auf Provider-Name
        return $provider;
    }

    private function extractSessionIdFromMessages(array $messages): ?string
    {
        // Durchsuche Messages nach Session-ID
        foreach ($messages as $message) {
            if (isset($message['metadata']['session_id'])) {
                return $message['metadata']['session_id'];
            }
        }
        return null;
    }

    private function createRequestPreview(array $options): string
    {
        if (isset($options['json']['messages'])) {
            $firstMessage = reset($options['json']['messages']);
            $content = $firstMessage['content'] ?? '';
            if (is_array($content)) {
                $content = json_encode($content);
            }
            return mb_substr($content, 0, 200);
        }

        if (isset($options['json']['contents'])) {
            $firstContent = reset($options['json']['contents']);
            $text = json_encode($firstContent);
            return mb_substr($text, 0, 200);
        }

        return 'N/A';
    }

    private function trackFailedRequest(
        string $provider,
        int $responseTime,
        string $errorMessage,
        array $requestOptions
    ): void {
        try {
            $userId = $GLOBALS['current_user_id'] ?? null;
            if (!$userId) {
                return;
            }

            $user = $this->em->find(\App\Entity\User::class, $userId);
            if (!$user) {
                return;
            }

            $model = $this->extractModelName([], $provider, $requestOptions);
            $agentType = $GLOBALS['current_agent_type'] ?? 'unknown';
            $requestPreview = $this->createRequestPreview($requestOptions);

            $this->tokenService->trackTokenUsage(
                user: $user,
                model: $model,
                agentType: $agentType,
                inputTokens: 0,
                outputTokens: 0,
                sessionId: null,
                requestPreview: $requestPreview,
                responseTimeMs: $responseTime,
                success: false,
                errorMessage: mb_substr($errorMessage, 0, 500)
            );

        } catch (\Throwable $e) {
            $this->logger->error('Failed to track failed request', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
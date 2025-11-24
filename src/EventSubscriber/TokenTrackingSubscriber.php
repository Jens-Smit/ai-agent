<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\TokenTrackingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Traversable;

/**
 * HTTP Client Decorator der Token-Usage tracked
 * Fängt Requests an AI APIs ab und extrahiert Token-Counts.
 */
class TokenTrackingHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    /**
     * @var array<string, string> Definierte Muster für AI APIs und ihre Provider-Namen
     */
    protected array $aiApiPatterns = [ 
        'generativelanguage.googleapis.com' => 'gemini',
        'api.openai.com' => 'openai',
        'api.anthropic.com' => 'anthropic',
    ];
    
    /**
     * @var \WeakMap<ResponseInterface, array> Speichert den Request-Kontext für Responses, die getracked werden müssen.
     * WeakMap verhindert Memory Leaks.
     */
    protected \WeakMap $responseContexts; 

    /**
     * Der Konstruktor injiziert die benötigten Services.
     */
    public function __construct(
        private HttpClientInterface $client,
        private TokenTrackingService $tokenService,
        private LoggerInterface $logger,
        private EntityManagerInterface $em
    ) {
        // Initialisiert die WeakMap zur Speicherung des Kontexts
        $this->responseContexts = new \WeakMap();
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $startTime = microtime(true);
        $aiProvider = $this->detectAiProvider($url);

        // 1. NICHTS ändern, wenn es keine AI-Anfrage ist
        if (!$aiProvider) {
            return $this->client->request($method, $url, $options);
        }

        try {
            /** @var ResponseInterface $response */
            $response = $this->client->request($method, $url, $options);
            
            // 2. WICHTIG: KEINEN Response-Methoden-Aufruf hier!
            // Nur den Kontext speichern. Die eigentliche Logik wird in stream() ausgeführt.
            $this->responseContexts[$response] = [
                'provider' => $aiProvider,
                'startTime' => $startTime,
                'options' => $options,
                'status' => 'pending' // Flag zur Steuerung der Abarbeitung in stream()
            ];

            return $response;

        } catch (\Throwable $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            
            // Fehler-Tracking, das keinen Response-Body konsumiert, bleibt hier.
            $this->logger->error('AI API request failed', [
                'provider' => $aiProvider,
                'error' => $e->getMessage(),
                'url' => $url
            ]);

            $this->trackFailedRequest($aiProvider, $responseTime, $e->getMessage(), $options);

            throw $e;
        }
    }
    
    /**
     * Fängt den Response-Stream ab, um Tracking nach Abschluss zu ermöglichen.
     * HINWEIS: Die Signatur MUSS exakt mit der HttpClientInterface übereinstimmen.
     */
    public function stream(ResponseInterface|Traversable|array $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // 1. Hole den echten Stream vom dekorierten Client
        $stream = $this->client->stream($responses, $timeout);

        // 2. Gebe eine anonyme Klasse zurück, die ResponseStreamInterface implementiert 
        // und die Tracking-Logik delegiert
        return new class(
            $stream,
            $this->responseContexts,
            $this->logger,
            $this->em,
            $this // Die Instanz des Dekorators selbst (um auf protected Methoden zuzugreifen)
        ) implements ResponseStreamInterface {
            
            private TokenTrackingHttpClient $tracker; 
            // FIX: Setze Eigenschaft auf nullable, um "Accessed before initialization" Fehler zu vermeiden.
            private ?\Traversable $innerIterator = null; 
            
            public function __construct(
                private ResponseStreamInterface $stream, 
                private \WeakMap $responseContexts, 
                private LoggerInterface $logger, 
                private EntityManagerInterface $em,
                TokenTrackingHttpClient $tracker
            ) {
                $this->tracker = $tracker;
            }

            /**
             * Gibt den Iterator für die foreach-Schleife zurück, implementiert unsere Tracking-Logik.
             */
            public function getIterator(): \Traversable
            {
                // Stelle sicher, dass der innere Iterator gesetzt ist (für den Fall, dass rewind nicht zuerst aufgerufen wird)
                if ($this->innerIterator === null) {
                    $this->innerIterator = $this->stream;
                }

                // Iteriere über den inneren Stream (Generator wird hier erstellt und zurückgegeben).
                foreach ($this->innerIterator as $response => $chunk) {
                    
                    // Gib den Chunk weiter
                    yield $response => $chunk;

                    // Tracking-Logik ausführen, wenn der letzte Chunk empfangen wurde
                    if ($chunk->isLast() && 
                        isset($this->responseContexts[$response]) && 
                        $this->responseContexts[$response]['status'] === 'pending') 
                    {
                        
                        $context = $this->responseContexts[$response];
                        $responseTime = (int) ((microtime(true) - $context['startTime']) * 1000);
                        
                        // Setze Status, um Doppelverarbeitung zu verhindern
                        $this->responseContexts[$response]['status'] = 'processed';

                        // Delegiere die Tracking-Logik an die HELPER-Methode der Hauptklasse
                        $this->tracker->trackTokensFromResponse(
                            $response, 
                            $context['provider'], 
                            $responseTime, 
                            $context['options']
                        );
                        
                        // Der WeakMap-Eintrag kann nun entfernt werden
                        unset($this->responseContexts[$response]);
                    }
                }
            }
            
            // --- Implementierung der fehlenden Iterator-Methoden (Delegation) ---
            // Wichtig: Rückgabetypen MÜSSEN EXAKT der Schnittstelle entsprechen (kein ?).

            /**
             * @return \Symfony\Contracts\HttpClient\ChunkInterface
             */
            public function current(): ChunkInterface
            {
                // FIX: Explizite Prüfung, da Eigenschaft nullable ist.
                if ($this->innerIterator === null) {
                     throw new \LogicException('Iterator not initialized before current() call.');
                }
                return $this->innerIterator->current();
            }

            /**
             * @return \Symfony\Contracts\HttpClient\ResponseInterface
             */
            public function key(): ResponseInterface
            {
                // FIX: Explizite Prüfung, da Eigenschaft nullable ist.
                if ($this->innerIterator === null) {
                     throw new \LogicException('Iterator not initialized before key() call.');
                }
                return $this->innerIterator->key();
            }

            public function next(): void
            {
                // Delegiere an den inneren Iterator.
                if ($this->innerIterator !== null) {
                     $this->innerIterator->next();
                }
            }

            public function rewind(): void
            {
                // Stelle sicher, dass der innere Iterator gesetzt ist, wenn rewind() aufgerufen wird.
                if ($this->innerIterator === null) {
                    $this->innerIterator = $this->stream;
                }
                // Delegiere an den inneren Iterator.
                $this->innerIterator->rewind();
            }

            public function valid(): bool
            {
                // Delegiere an den inneren Iterator.
                // Prüfe zuerst auf Null.
                return $this->innerIterator !== null && $this->innerIterator->valid();
            }
            // --- Ende der Iterator-Methoden ---
        };
    }

    protected function detectAiProvider(string $url): ?string
    {
        foreach ($this->aiApiPatterns as $pattern => $provider) {
            if (str_contains($url, $pattern)) {
                return $provider;
            }
        }
        return null;
    }

    // WICHTIG: Sichtbarkeit auf 'protected' geändert, damit die anonyme Klasse darauf zugreifen kann.
    protected function trackTokensFromResponse(
        ResponseInterface $response,
        string $provider,
        int $responseTime,
        array $requestOptions
    ): void {
        try {
            if ($response->getStatusCode() >= 400) {
                 return;
            }

            // getContent(false) liest den Inhalt einmal in den Cache des Response-Objekts,
            // was in diesem Kontext (nach isLast() in stream()) sicher ist.
            $content = $response->getContent(false); 
            $data = json_decode($content, true);

            if (!$data) {
                $this->logger->warning('TOKEN_ Could not decode JSON response for tracking', [
                    'provider' => $provider,
                    'content_preview' => mb_substr($content, 0, 100)
                ]);
                return;
            }

            $tokenData = $this->extractTokenData($data, $provider);

            if (!$tokenData) {
                // Das ist in Ordnung, wenn z.B. nur ein Text-Response ohne Usage-Header kommt.
                $this->logger->debug('TOKEN_ No token usage data found in response', ['provider' => $provider]);
                return;
            }

            // Sicherstellen, dass der User-Kontext vorhanden ist
            $userId = $GLOBALS['current_user_id'] ?? null;
            if (!$userId) {
                $this->logger->debug('TOKEN_ No user context for token tracking');
                return;
            }

            // Da wir in Symfony/Doctrine sind, muss die Entity-Klasse den korrekten Namespace haben
            $user = $this->em->find('App\Entity\User', $userId);
            if (!$user) {
                $this->logger->warning('TOKEN_ User entity not found for tracking', ['userId' => $userId]);
                return;
            }

            $sessionId = null;
            if (isset($requestOptions['json']['messages'])) {
                $sessionId = $this->extractSessionIdFromMessages($requestOptions['json']['messages']);
            } elseif (isset($requestOptions['json']['session'])) {
                 $sessionId = $requestOptions['json']['session'];
            }

            $model = $this->extractModelName($data, $provider, $requestOptions);
            $agentType = $GLOBALS['current_agent_type'] ?? 'unknown';
            $requestPreview = $this->createRequestPreview($requestOptions);

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
            $this->logger->error('Failed to track token usage (internal error)', [
                'error' => $e->getMessage(),
                'provider' => $provider
            ]);
        }
    }

    protected function extractTokenData(array $data, string $provider): ?array
    {
        return match($provider) {
            'gemini' => $this->extractGeminiTokens($data),
            'openai' => $this->extractOpenAiTokens($data),
            'anthropic' => $this->extractAnthropicTokens($data),
            default => null
        };
    }

    protected function extractGeminiTokens(array $data): ?array
    {
        $metadata = $data['usageMetadata'] ?? null;
        if (!$metadata) {
            return null;
        }

        return [
            'input_tokens' => $metadata['promptTokenCount'] ?? 0,
            'output_tokens' => $metadata['candidatesTokenCount'] ?? 0,
        ];
    }

    protected function extractOpenAiTokens(array $data): ?array
    {
        $usage = $data['usage'] ?? null;
        if (!$usage) {
            return null;
        }

        return [
            'input_tokens' => $usage['prompt_tokens'] ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
        ];
    }

    protected function extractAnthropicTokens(array $data): ?array
    {
        $usage = $data['usage'] ?? null;
        if (!$usage) {
            return null;
        }

        return [
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ];
    }

    protected function extractModelName(array $data, string $provider, array $requestOptions): string
    {
        if (isset($requestOptions['url'])) {
            if (preg_match('/models\/([^:\/]+)/', $requestOptions['url'], $matches)) {
                return $matches[1];
            }
        }
        if (isset($requestOptions['json']['model'])) {
            return $requestOptions['json']['model'];
        }
        if (isset($data['model'])) {
            return $data['model'];
        }
        return $provider;
    }

    protected function extractSessionIdFromMessages(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (isset($message['metadata']['session_id'])) {
                return $message['metadata']['session_id'];
            }
        }
        return null;
    }

    protected function createRequestPreview(array $options): string
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

    protected function trackFailedRequest(
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

            $user = $this->em->find('App\Entity\User', $userId);
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
            $this->logger->error('Failed to track failed request (internal error)', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
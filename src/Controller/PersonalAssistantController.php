<?php

namespace App\Controller;

use App\DTO\AgentPromptRequest; // Stelle sicher, dass dieses DTO existiert
use OpenApi\Attributes as OA;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use App\Service\AgentStatusService;
#[Route('/api', name: 'api_')]
#[OA\Tag(name: 'AI Agent - Personal Assistant')]
class PersonalAssistantController extends AbstractController
{
    private const SESSION_MESSAGE_BAG_KEY = 'ai_agent_personal_assistant_messages';

    public function __construct(
        private LoggerInterface $logger,
        private AgentStatusService $agentStatusService
       
    ) {
    }

    #[Route('/agent', name: 'agent', methods: ['POST'])]
    #[OA\Post(
        summary: 'Personal Assistant AI Agent',
        description: 'Interagiert mit dem persönlichen Assistenten unter Beibehaltung des Kontexts.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AgentPromptRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Erfolgreiche Antwort des Agenten.'),
            new OA\Response(response: 400, description: 'Ungültige Anfrage (z.B. fehlender Prompt).'),
            new OA\Response(response: 503, description: 'Service momentan nicht verfügbar.')
        ]
    )]
    public function PersonalAssistent(
        Request $request,
        #[Autowire(service: 'ai.agent.personal_assistent')]
        AgentInterface $agent,
    ): JsonResponse {
        // Konfiguration für die Retry-Logik
        $maxRetries = 5;
        $retryDelaySeconds = 60;
        
        // Hole den Prompt aus dem Request
        $data = json_decode($request->getContent(), true);
        $userPrompt = $data['prompt'] ?? '';

        if (empty($userPrompt)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Kein Prompt angegeben',
            ], Response::HTTP_BAD_REQUEST);
        }
    
        $session = $request->getSession();
        $this->logger->info('PersonalAssistent Anfrage erhalten', [
            'prompt' => $userPrompt,
            'user_id' => $this->getUser()?->getId(),
        ]);

        // Lade die bestehende MessageBag aus der Session oder erstelle eine neue
        /** @var MessageBag $messages */
        $messages = $session->get(self::SESSION_MESSAGE_BAG_KEY);
        if (!$messages instanceof MessageBag) {
            $messages = new MessageBag();
        }

        // Füge die aktuelle Benutzernachricht hinzu
        $messages->add(Message::ofUser($userPrompt));

        // Spezieller Befehl zum Zurücksetzen des Gedächtnisses
        if (strtolower(trim($userPrompt)) === 'gedächtnis löschen') {
            $session->remove(self::SESSION_MESSAGE_BAG_KEY);
            return $this->json([
                'status' => 'success',
                'message' => 'Das Gedächtnis des Agenten wurde gelöscht.',
            ], Response::HTTP_OK);
        }

        // Versuche den Agent mit Retry-Logik aufzurufen
        $attempt = 1;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            try {
                $this->logger->info('Agent-Aufruf Versuch', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                ]);

                // Rufe den Agent auf
                $result = $agent->call($messages);
                
                // Erfolgreich - gib das Ergebnis zurück
                $this->logger->info('Agent-Aufruf erfolgreich', [
                    'attempt' => $attempt,
                ]);

                // Füge die Agentenantwort zur MessageBag hinzu und speichere sie
                $messages->add(Message::ofAssistant($result->getContent()));
                $session->set(self::SESSION_MESSAGE_BAG_KEY, $messages);

                return $this->json([
                    'status' => 'success',
                    'message' => 'Antwort erfolgreich generiert',
                    'response' => $result->getContent(),
                    'metadata' => [
                        'attempts' => $attempt,
                        'token_usage' => $result->getMetadata()->get('token_usage'),
                    ],
                ], Response::HTTP_OK);

            } catch (\Throwable $e) {
                $lastError = $e;
                $errorMessage = $e->getMessage();
                $isRetriable = false;

                // 1. Prüfen auf standardmäßige retriable HTTP-Fehler (5xx)
                if ($e instanceof ServerExceptionInterface) {
                    $isRetriable = true;
                }
                // 2. Prüfen auf Transport-/Verbindungsfehler
                else if ($e instanceof TransportExceptionInterface) {
                    $isRetriable = true;
                }
                // 3. Prüfen auf den spezifischen, in der AI Platform gewrappten 503-Fehler
                //    (Der Fehler, der in Ihrem Log einen 500-Exit verursacht hat)
                else if (str_contains($errorMessage, '503') || str_contains($errorMessage, 'UNAVAILABLE') || str_contains($errorMessage, 'overloaded')) {
                    $isRetriable = true;
                }
                
                if ($isRetriable) {
                    $this->logger->warning('Retriable Fehler beim Agent-Aufruf erkannt', [
                        'attempt' => $attempt,
                        'error_type' => $e::class,
                        'message' => $errorMessage,
                        'status_code' => ($e instanceof ServerExceptionInterface) ? $e->getResponse()->getStatusCode() : 'unknown',
                    ]);

                    // Wenn es nicht der letzte Versuch ist, warte und versuche es erneut
                    if ($attempt < $maxRetries) {
                         $this->logger->warning(sprintf('Retrying AI agent call in %d seconds...', $retryDelaySeconds));
                        $this->agentStatusService->addStatus(sprintf('Warte %d Sekunden vor erneutem Versuch...', $retryDelaySeconds));   
                        sleep($retryDelaySeconds); // 60 Sekunden warten
                        $attempt++;
                        continue;
                    }

                    // Letzter Versuch fehlgeschlagen
                    break;
                }

                // Nicht retriable Fehler (z.B. 4xx oder echter unerwarteter Fehler)
                $this->logger->error('Unerwarteter Fehler beim Agent-Aufruf (Kein Retry)', [
                    'attempt' => $attempt,
                    'error_type' => $e::class,
                    'error' => $errorMessage,
                    'trace' => $e->getTraceAsString(),
                ]);

                // Gebe den nicht retriable Fehler sofort als HTTP 500 zurück
                return $this->json([
                    'status' => 'error',
                    'message' => 'Ein unerwarteter, nicht behebbarer Fehler ist aufgetreten',
                    'error' => $errorMessage,
                    'attempts' => $attempt,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // Alle Versuche fehlgeschlagen (es war ein retriable Fehler)
        $this->logger->error('Alle Agent-Aufrufe fehlgeschlagen', [
            'total_attempts' => $maxRetries,
            'last_error' => $lastError?->getMessage(),
        ]);

        // Rückgabe des 503 Service Unavailable nach maximalen Retries
        return $this->json([
            'status' => 'error',
            'message' => 'Der Service ist momentan nicht verfügbar. Bitte versuchen Sie es später erneut.',
            'error' => $lastError?->getMessage() ?? 'Unbekannter Fehler',
            'attempts' => $maxRetries,
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    // OpenAPI Schema for AgentPromptRequest DTO (Bleibt hier, da es auch vom DevAgent genutzt wird)
    #[OA\Schema(
        schema: 'AgentPromptRequest',
        required: ['prompt'],
        properties: [
            new OA\Property(property: 'prompt', type: 'string', example: 'Generate a deployment script for nginx'),
            new OA\Property(property: 'context', type: 'string', nullable: true),
            new OA\Property(property: 'options', type: 'object', nullable: true)
        ]
    )]
    private ?string $agentPromptRequestSchema = null; // Dummy property for schema definition
}
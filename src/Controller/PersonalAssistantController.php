<?php

namespace App\Controller;

use App\DTO\AgentPromptRequest; // Stelle sicher, dass dieses DTO existiert
use OpenApi\Attributes as OA;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route('/api', name: 'api_')]
#[OA\Tag(name: 'AI Agent - Personal Assistant')]
class PersonalAssistantController extends AbstractController
{
    private const SESSION_MESSAGE_BAG_KEY = 'ai_agent_personal_assistant_messages';

    public function __construct(
        private LoggerInterface $logger
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
        // ... (Der gesamte Inhalt Ihrer ursprünglichen PersonalAssistent-Methode,
        // jedoch ohne die AgentStatusService- und Validator-Injektionen, die nur
        // der DevAgentController benötigt.)
        
        // Konfiguration
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

            } catch (\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface $e) {
                // 503 Service Unavailable oder andere 5xx Fehler
                $lastError = $e;
                
                $this->logger->warning('Server-Fehler beim Agent-Aufruf', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                    'status_code' => $e->getResponse()->getStatusCode() ?? 'unknown',
                ]);

                // Wenn es nicht der letzte Versuch ist, warte
                if ($attempt < $maxRetries) {
                    $this->logger->info('Warte vor erneutem Versuch', [
                        'wait_seconds' => $retryDelaySeconds,
                        'next_attempt' => $attempt + 1,
                    ]);
                    
                    sleep($retryDelaySeconds);
                    $attempt++;
                    continue;
                }

                // Letzter Versuch fehlgeschlagen
                break;

            } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
                // Netzwerk- oder Verbindungsfehler
                $lastError = $e;
                
                $this->logger->warning('Transport-Fehler beim Agent-Aufruf', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    sleep($retryDelaySeconds);
                    $attempt++;
                    continue;
                }

                break;

            } catch (\Throwable $e) {
                // Alle anderen Fehler - kein Retry
                $lastError = $e;
                
                $this->logger->error('Unerwarteter Fehler beim Agent-Aufruf', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return $this->json([
                    'status' => 'error',
                    'message' => 'Ein unerwarteter Fehler ist aufgetreten',
                    'error' => $e->getMessage(),
                    'attempts' => $attempt,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // Alle Versuche fehlgeschlagen
        $this->logger->error('Alle Agent-Aufrufe fehlgeschlagen', [
            'total_attempts' => $attempt,
            'last_error' => $lastError?->getMessage(),
        ]);

        return $this->json([
            'status' => 'error',
            'message' => 'Der Service ist momentan nicht verfügbar. Bitte versuchen Sie es später erneut.',
            'error' => $lastError?->getMessage() ?? 'Unbekannter Fehler',
            'attempts' => $attempt,
            'max_retries' => $maxRetries,
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
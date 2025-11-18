<?php
// src/Service/ToolCapabilityChecker.php

declare(strict_types=1);

namespace App\Service;

use App\Tool\ToolRequestor;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tool Capability Checker
 * 
 * Prüft ob erforderliche Tools verfügbar sind und fordert
 * automatisch neue Tools beim DevAgent an, falls nötig.
 */
final class ToolCapabilityChecker
{
    // Mapping von Capabilities zu Tool-Namen
    private const CAPABILITY_TOOL_MAP = [
        'apartment_search' => 'immobilien_search_tool',
        'real_estate_search' => 'immobilien_search_tool',
        'property_search' => 'immobilien_search_tool',
        'calendar_management' => 'google_calendar_create_event',
        'appointment_scheduling' => 'google_calendar_create_event',
        'email_sending' => 'gmail_send_tool',
        'slack_messaging' => 'slack_integration_tool',
        'database_query' => 'database_query_tool',
        'file_upload' => 'file_upload_tool',
        'image_processing' => 'image_processor_tool',
        'pdf_generation' => 'PdfGenerator',
        'web_scraping' => 'web_scraper',
        'api_calling' => 'api_client',
    ];

    // Template-Prompts für Tool-Entwicklung
    private const TOOL_TEMPLATES = [
        'immobilien_search_tool' => <<<PROMPT
Entwickle ein Tool für Immobiliensuche mit folgenden Anforderungen:

NAME: ImmobilienSearchTool
BESCHREIBUNG: Durchsucht Immobilienportale (ImmobilienScout24, Immowelt) nach Wohnungen/Häusern

PARAMETER:
- city (string, required): Stadt (z.B. "Berlin")
- district (string, optional): Stadtteil (z.B. "Mitte")
- property_type (enum: apartment|house, default: apartment)
- min_price (int, optional): Minimaler Preis in EUR
- max_price (int, required): Maximaler Preis in EUR
- min_rooms (float, optional): Minimale Anzahl Zimmer
- max_rooms (float, optional): Maximale Anzahl Zimmer
- min_size (int, optional): Minimale Größe in m²
- radius_km (int, optional): Suchradius in km (für Umkreissuche)

RÜCKGABE:
Array mit Immobilien-Objekten:
[
  {
    "title": "Schöne 3-Zimmer-Wohnung",
    "price": 1500,
    "size": 80,
    "rooms": 3,
    "address": "Invalidenstr. 42, 10115 Berlin",
    "url": "https://...",
    "images": ["url1", "url2"],
    "contact": {"phone": "...", "email": "..."}
  }
]

IMPLEMENTIERUNG:
1. Nutze WebScraperTool für ImmobilienScout24
2. Nutze ApiClientTool falls API verfügbar
3. Implementiere Caching (15 Minuten)
4. Fehlerbehandlung für Rate-Limits
5. Validierung aller Parameter

TESTS:
- Erfolgreiche Suche
- Keine Ergebnisse
- Ungültige Parameter
- API-Fehler

WICHTIG: 
- Respektiere robots.txt
- Rate-Limiting beachten
- User-Agent setzen
PROMPT,

        'gmail_send_tool' => <<<PROMPT
Entwickle ein Tool zum Versenden von E-Mails über Gmail API:

NAME: GmailSendTool
BESCHREIBUNG: Sendet E-Mails über Gmail API im Namen des authentifizierten Users

PARAMETER:
- to (string, required): Empfänger-E-Mail
- subject (string, required): Betreff
- body (string, required): E-Mail-Body (Text oder HTML)
- cc (array, optional): CC-Empfänger
- bcc (array, optional): BCC-Empfänger
- attachments (array, optional): Dateianhänge

VORAUSSETZUNGEN:
- Google OAuth Integration muss vorhanden sein
- Gmail API Scope erforderlich: https://www.googleapis.com/auth/gmail.send
- GoogleClientService nutzen für Authentication

RÜCKGABE:
{
  "status": "success",
  "message_id": "...",
  "thread_id": "..."
}

IMPLEMENTIERUNG:
1. Hole Gmail Service via GoogleClientService
2. Erstelle MIME-Message
3. Base64-encode Message
4. Sende via Gmail API
5. Error-Handling für Auth-Fehler

TESTS:
- Erfolgreicher Versand
- Auth-Fehler
- Ungültige E-Mail-Adresse
- Mit/Ohne Attachments
PROMPT,

        'slack_integration_tool' => <<<PROMPT
Entwickle ein Tool für Slack-Integration:

NAME: SlackIntegrationTool
BESCHREIBUNG: Sendet Nachrichten an Slack-Channels/Users

PARAMETER:
- channel (string, required): Channel-Name oder User-ID
- message (string, required): Nachricht
- thread_ts (string, optional): Thread-Timestamp für Antworten
- blocks (array, optional): Slack Block Kit Blocks
- attachments (array, optional): Message Attachments

KONFIGURATION:
- Benötigt SLACK_BOT_TOKEN in .env
- Scopes: chat:write, channels:read, users:read

RÜCKGABE:
{
  "status": "success",
  "ts": "1234567890.123456",
  "channel": "C1234567890"
}

IMPLEMENTIERUNG:
1. Nutze ApiClientTool mit Slack API
2. Token aus Environment
3. Validiere Channel existiert
4. Formatiere Message (Markdown → Slack Markup)

TESTS:
- Nachricht an Channel
- Nachricht an User (DM)
- Thread-Reply
- Mit Blocks
- Fehlerbehandlung
PROMPT
    ];

    private array $availableTools = [];

    public function __construct(
        #[Autowire(service: 'ai.toolbox.personal_assistent')]
        private Toolbox $toolbox,
        private ToolRequestor $toolRequestor,
        private LoggerInterface $logger
    ) {
        $this->loadAvailableTools();
    }

    /**
     * Prüft ob alle erforderlichen Capabilities verfügbar sind
     * 
     * @param array $requiredCapabilities z.B. ['apartment_search', 'calendar_management']
     * @return array ['missing' => [...], 'available' => [...]]
     */
    public function checkCapabilities(array $requiredCapabilities): array
    {
        $available = [];
        $missing = [];

        foreach ($requiredCapabilities as $capability) {
            $toolName = self::CAPABILITY_TOOL_MAP[$capability] ?? null;

            if (!$toolName) {
                $this->logger->warning('Unknown capability requested', ['capability' => $capability]);
                $missing[] = $capability;
                continue;
            }

            if ($this->isToolAvailable($toolName)) {
                $available[] = $capability;
            } else {
                $missing[] = $capability;
            }
        }

        return [
            'available' => $available,
            'missing' => $missing
        ];
    }

    /**
     * Fordert fehlende Tools beim DevAgent an
     */
    public function requestMissingTools(array $missingCapabilities): array
    {
        $results = [];

        foreach ($missingCapabilities as $capability) {
            $toolName = self::CAPABILITY_TOOL_MAP[$capability] ?? null;

            if (!$toolName) {
                $this->logger->warning('Cannot request tool for unknown capability', ['capability' => $capability]);
                continue;
            }

            $prompt = $this->getToolDevelopmentPrompt($toolName);

            if (!$prompt) {
                $this->logger->error('No development prompt available for tool', ['tool' => $toolName]);
                continue;
            }

            $this->logger->info('Requesting tool development from DevAgent', [
                'tool' => $toolName,
                'capability' => $capability
            ]);

            try {
                $result = ($this->toolRequestor)($prompt);
                $results[$toolName] = $result;
                
                if ($result['status'] === 'success') {
                    $this->logger->info('Tool development requested successfully', ['tool' => $toolName]);
                } else {
                    $this->logger->error('Tool development request failed', [
                        'tool' => $toolName,
                        'result' => $result
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to request tool development', [
                    'tool' => $toolName,
                    'error' => $e->getMessage()
                ]);
                
                $results[$toolName] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Automatische Capability-Erkennung aus User-Intent
     */
    public function detectRequiredCapabilities(string $userIntent): array
    {
        $intent = strtolower($userIntent);
        $required = [];

        // Keywords für verschiedene Capabilities
        $patterns = [
            'apartment_search' => ['wohnung', 'apartment', 'immobilie', 'mieten', 'zimmer'],
            'calendar_management' => ['termin', 'kalendar', 'meeting', 'appointment', 'besichtigung'],
            'email_sending' => ['email', 'mail', 'nachricht senden', 'schreib eine mail'],
            'slack_messaging' => ['slack', 'slack nachricht', 'team benachrichtigen'],
            'web_scraping' => ['webseite', 'scrape', 'extrahiere von', 'daten von webseite'],
            'pdf_generation' => ['pdf', 'dokument erstellen', 'report generieren'],
        ];

        foreach ($patterns as $capability => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($intent, $keyword)) {
                    $required[] = $capability;
                    break; // Nur einmal pro Capability
                }
            }
        }

        return array_unique($required);
    }

    /**
     * Lädt verfügbare Tools aus Toolbox
     */
    private function loadAvailableTools(): void
    {
        try {
            $tools = $this->toolbox->getTools();
            
            foreach ($tools as $tool) {
                $this->availableTools[] = $tool->getName();
            }
            
            $this->logger->info('Loaded available tools', [
                'count' => count($this->availableTools),
                'tools' => $this->availableTools
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load available tools', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Prüft ob Tool verfügbar ist
     */
    private function isToolAvailable(string $toolName): bool
    {
        return in_array($toolName, $this->availableTools, true);
    }

    /**
     * Holt Development-Prompt für Tool
     */
    private function getToolDevelopmentPrompt(string $toolName): ?string
    {
        return self::TOOL_TEMPLATES[$toolName] ?? null;
    }

    /**
     * Gibt Liste aller verfügbaren Tools zurück
     */
    public function getAvailableTools(): array
    {
        return $this->availableTools;
    }
}
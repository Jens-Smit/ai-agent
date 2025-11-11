<?php
namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Psr\Log\LoggerInterface;

/**
 * Ein Service, der dem AI Agent hilft, seine Tool-Fähigkeiten zu bewerten und neue Tool-Entwicklung anzufordern.
 */
#[AsTool(
    name: 'assess_and_request_tool_development',
    description: 'Assesses if a given user task can be fully completed with the agent\'s current tools. If not, it generates a precise prompt for the /api/devAgent endpoint to request the development of a new Symfony AI agent tool.'
)]
final readonly class ToolDevelopmentAssistantTools
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Bewertet, ob eine gegebene Benutzeraufgabe mit den aktuellen Tools des Agenten vollständig erledigt werden kann.
     * Falls nicht, generiert es einen präzisen Prompt für den /api/devAgent Endpunkt, um die Entwicklung eines neuen Tools anzufordern.
     *
     * @param string $currentTaskDescription Eine detaillierte Beschreibung der aktuellen Aufgabe des Benutzers
     * @param array $availableToolDescriptions Ein Array mit detaillierten Beschreibungen aller derzeit verfügbaren Tools
     * @return array Gibt ein Array mit 'canCompleteTask' (bool), 'reason' (string) und optional 'toolDevelopmentPrompt' (string) zurück
     */
    public function __invoke(string $currentTaskDescription, array $availableToolDescriptions): array
    {
        $this->logger->info('Bewerte Aufgabe und verfügbare Tools', [
            'task' => $currentTaskDescription,
            'available_tools_count' => count($availableToolDescriptions)
        ]);

        $missingCapabilities = [];
        $analysisDetails = [];

        // Beispiel 1: Datenbank-Schema-Änderungen
        if ((str_contains(strtolower($currentTaskDescription), 'database schema') || 
             str_contains(strtolower($currentTaskDescription), 'doctrine entity')) &&
            !$this->hasToolFor('doctrine migration', $availableToolDescriptions) &&
            !$this->hasToolFor('database change', $availableToolDescriptions) &&
            !$this->hasToolFor('schema update', $availableToolDescriptions)
        ) {
            $missingCapabilities['database_migration'] = 'Die Aufgabe erfordert Datenbank-Schema-Änderungen, aber es ist kein Tool für Doctrine Migrations oder Datenbank-Updates verfügbar.';
        }

        // Beispiel 2: Web-Scraping
        if ((str_contains(strtolower($currentTaskDescription), 'scrape web') || 
             str_contains(strtolower($currentTaskDescription), 'extract data from url')) &&
            !$this->hasToolFor('web scraper', $availableToolDescriptions) &&
            !$this->hasToolFor('fetch url content', $availableToolDescriptions)
        ) {
            $missingCapabilities['web_scraping'] = 'Die Aufgabe erfordert das Abrufen und Parsen externer Webinhalte, aber es ist kein Web-Scraping-Tool verfügbar.';
        }

        // Beispiel 3: Komplexe Datei-Strukturen
        if ((str_contains(strtolower($currentTaskDescription), 'generate complex file') || 
             str_contains(strtolower($currentTaskDescription), 'create project structure')) &&
            !$this->hasToolFor('file generation', $availableToolDescriptions) &&
            !$this->hasToolFor('project scaffolding', $availableToolDescriptions)
        ) {
            $missingCapabilities['file_scaffolding'] = 'Die Aufgabe erfordert die Generierung komplexer Dateistrukturen oder Projekt-Scaffolding, aber es ist kein dediziertes Tool dafür verfügbar.';
        }

        // Beispiel 4: E-Mail-Versand
        if (str_contains(strtolower($currentTaskDescription), 'send email') &&
            !$this->hasToolFor('mailer', $availableToolDescriptions) &&
            !$this->hasToolFor('email', $availableToolDescriptions)
        ) {
            $missingCapabilities['email_sending'] = 'Die Aufgabe erfordert den Versand von E-Mails, aber es ist kein Mailer-Tool verfügbar.';
        }

        // Beispiel 5: PDF-Generierung
        if (str_contains(strtolower($currentTaskDescription), 'generate pdf') &&
            !$this->hasToolFor('pdf', $availableToolDescriptions)
        ) {
            $missingCapabilities['pdf_generation'] = 'Die Aufgabe erfordert PDF-Generierung, aber es ist kein PDF-Tool verfügbar.';
        }

        if (empty($missingCapabilities)) {
            $this->logger->info('Aufgabe kann mit vorhandenen Tools erledigt werden');
            return [
                'canCompleteTask' => true,
                'reason' => 'Basierend auf der aktuellen Analyse scheint der Agent die notwendigen Tools zu haben, um diese Aufgabe zu erledigen.',
                'analysisDetails' => $analysisDetails,
            ];
        }

        $missingReason = 'Dem Agenten fehlen folgende Fähigkeiten, um die Aufgabe vollständig zu erledigen: ' . 
                        implode(', ', array_values($missingCapabilities)) . '.';

        $toolDevelopmentPrompt = $this->generateToolDevelopmentPrompt($currentTaskDescription, $missingCapabilities);

        $this->logger->warning('Aufgabe kann nicht vollständig erledigt werden', [
            'missing_capabilities' => array_keys($missingCapabilities)
        ]);

        return [
            'canCompleteTask' => false,
            'reason' => $missingReason,
            'analysisDetails' => $analysisDetails,
            'toolDevelopmentPrompt' => $toolDevelopmentPrompt,
        ];
    }

    /**
     * Hilfsfunktion um zu prüfen, ob eine Tool-Beschreibung eine bestimmte Fähigkeit enthält.
     */
    private function hasToolFor(string $capability, array $availableToolDescriptions): bool
    {
        foreach ($availableToolDescriptions as $description) {
            if (str_contains(strtolower($description), strtolower($capability))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generiert einen detaillierten Prompt für den /api/devAgent Endpunkt.
     */
    private function generateToolDevelopmentPrompt(string $currentTaskDescription, array $missingCapabilities): string
    {
        $promptLines = [
            'Entwickle ein neues Symfony AI Agent Tool für den AI Agent. Der Agent benötigt dieses Tool, um eine Benutzeranfrage vollständig zu bearbeiten.',
            '',
            '**Original-Aufgabe des Benutzers:**',
            $currentTaskDescription,
            '',
            '**Identifizierte fehlende Fähigkeiten:**',
        ];

        foreach ($missingCapabilities as $key => $description) {
            $promptLines[] = "- " . $description;
        }

        $promptLines[] = '';
        $promptLines[] = '**Tool-Entwicklungsanfrage:**';
        $promptLines[] = 'Bitte erstelle eine neue PHP-Klasse im `App\\Tool` Namespace. Diese Klasse sollte eine oder mehrere Methoden enthalten, die mit `#[AsTool]` annotiert sind, um die fehlende Funktionalität bereitzustellen.';
        $promptLines[] = 'Konzentriere dich darauf, zuerst das kritischste Tool zu entwickeln, wie durch die fehlenden Fähigkeiten identifiziert.';
        $promptLines[] = 'Das Tool sollte robust, produktionsreif und vollständig getestet sein.';
        $promptLines[] = 'Füge alle notwendigen `use` Statements, Typ-Deklarationen, PHPDoc-Kommentare hinzu und halte dich an PSR-12 Standards.';
        $promptLines[] = '';
        $promptLines[] = '**Spezifische Anforderungen für das/die neue(n) Tool(s):**';

        if (isset($missingCapabilities['database_migration'])) {
            $promptLines[] = 'Für Datenbank-Migrations-Fähigkeit:';
            $promptLines[] = '  - Erstelle ein Tool, z.B. `DatabaseMigrationTools`, mit einer Methode wie `generateDoctrineMigration(string $migrationName): string`.';
            $promptLines[] = '  - Dieses Tool sollte Doctrine Migration Generierung auslösen können.';
            $promptLines[] = '';
        }

        if (isset($missingCapabilities['web_scraping'])) {
            $promptLines[] = 'Für Web-Scraping-Fähigkeit:';
            $promptLines[] = '  - Erstelle ein Tool, z.B. `WebScraperTools`, mit einer Methode wie `scrapeUrl(string $url, array $cssSelectors): array`.';
            $promptLines[] = '  - Dieses Tool sollte eine URL und CSS-Selektoren akzeptieren, um spezifische Daten zu extrahieren.';
            $promptLines[] = '';
        }

        if (isset($missingCapabilities['file_scaffolding'])) {
            $promptLines[] = 'Für File-Scaffolding-Fähigkeit:';
            $promptLines[] = '  - Erstelle ein Tool, z.B. `FileScaffoldingTools`, mit einer Methode wie `scaffoldProject(string $projectName, string $templateType): string`.';
            $promptLines[] = '';
        }

        if (isset($missingCapabilities['email_sending'])) {
            $promptLines[] = 'Für E-Mail-Versand-Fähigkeit:';
            $promptLines[] = '  - Erstelle ein Tool, z.B. `EmailSenderTools`, mit einer Methode wie `sendEmail(string $to, string $subject, string $body): bool`.';
            $promptLines[] = '';
        }

        if (isset($missingCapabilities['pdf_generation'])) {
            $promptLines[] = 'Für PDF-Generierungs-Fähigkeit:';
            $promptLines[] = '  - Erstelle ein Tool, z.B. `PdfGeneratorTools`, mit einer Methode wie `generatePdf(string $content, array $options): string`.';
            $promptLines[] = '';
        }

        $promptLines[] = 'Bitte liefere den generierten PHP-Code für die Tool-Klasse(n), die entsprechende(n) PHPUnit-Testdatei(en) mit mindestens 70% Abdeckung, und die notwendige Symfony-Konfiguration (z.B. `services.yaml` Snippet).';
        $promptLines[] = 'Stelle sicher, dass das neue Tool sich nahtlos in das Symfony AI Agent Ökosystem integriert.';

        return implode("\n", $promptLines);
    }
}
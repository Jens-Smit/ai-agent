<?php
// src/Service/ToolCapabilityChecker.php

declare(strict_types=1);

namespace App\Service;

use App\Tool\ToolRequestor;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dynamic Tool Capability Checker mit verbesserter Multi-Tool-Erkennung
 */
final class ToolCapabilityChecker
{
    private array $availableToolDefinitions = [];

    public function __construct(
        #[Autowire(service: 'ai.fault_tolerant_toolbox.personal_assistent.inner')]
        private Toolbox $toolbox,
        
        #[Autowire(service: 'ai.traceable_platform.gemini')]
        private PlatformInterface $platform,
        
        private ToolRequestor $toolRequestor,
        private LoggerInterface $logger
    ) {
        $this->loadAvailableToolDefinitions();
    }

    /**
     * Analysiert den Intent und prüft dynamisch auf Tools
     */
    public function ensureCapabilitiesFor(string $userIntent): array
    {
        // 1. LLM fragen: Haben wir die nötigen Tools?
        $analysis = $this->analyzeToolAvailability($userIntent);

        // VERBESSERT: Prüfe auf tool_combination (mehrere Tools)
        if ($analysis['has_capability'] ?? false) {
            if (isset($analysis['tool_combination']) && is_array($analysis['tool_combination'])) {
                $this->logger->info('Tool combination found', [
                    'tools' => $analysis['tool_combination']
                ]);
                return [
                    'status' => 'available',
                    'tools' => $analysis['tool_combination'],
                    'reasoning' => $analysis['reasoning'] ?? ''
                ];
            }
            
            if (isset($analysis['tool_name'])) {
                $this->logger->info('Single tool found', ['tool' => $analysis['tool_name']]);
                return [
                    'status' => 'available',
                    'tool' => $analysis['tool_name'],
                    'reasoning' => $analysis['reasoning'] ?? ''
                ];
            }
        }

        // 2. Wenn nicht: Tool dynamisch anfordern
        $this->logger->info('No matching tools found. Requesting creation.', [
            'intent' => $userIntent
        ]);
        $techDescription = $analysis['missing_logic_description'] ?? "Tool logic for: $userIntent";
        
        return $this->requestDynamicToolCreation($userIntent, $techDescription);
    }

    /**
     * Erstellt einen Ad-Hoc Agenten mit verbessertem Prompt
     */
    private function analyzeToolAvailability(string $userIntent): array
    {
        $toolDescriptions = json_encode($this->availableToolDefinitions, JSON_PRETTY_PRINT);

        // VERBESSERTER System Prompt
        $systemPrompt = <<<PROMPT
        Du bist ein intelligenter Tool-Analyst für eine Symfony AI Anwendung.
        
        VERFÜGBARE TOOLS:
        $toolDescriptions
        
        AUFGABE:
        Analysiere, ob der User Intent mit den VORHANDENEN Tools erfüllt werden kann.
        
        WICHTIGE REGELN:
        1. Prüfe die BESCHREIBUNG der Tools, nicht nur den Namen
        2. MEHRERE Tools können KOMBINIERT werden, um komplexe Aufgaben zu lösen
        3. Nur wenn WIRKLICH KEINE Kombination möglich ist, gib has_capability: false zurück
        
        BEISPIELE FÜR TOOL-KOMBINATIONEN:
        - "Dokument lesen und PDF erstellen" → UserDocumentTool + PdfGenerator
        - "Webseite scrapen und Email senden" → WebScraperTool + SendMailTool
        - "Kalender-Event nach Jobsuche erstellen" → JobSearchTool + GoogleCalendarCreateEventTool
        
        ANTWORTE NUR IM JSON-FORMAT (ohne Markdown):
        {
            "has_capability": boolean,
            "tool_name": "Name des Tools (falls nur 1 Tool)" oder null,
            "tool_combination": ["Tool1", "Tool2", ...] oder null,
            "reasoning": "Kurze Begründung, warum diese Tools ausreichen",
            "missing_logic_description": "Nur falls has_capability false: Was fehlt konkret?"
        }
        PROMPT;

        $checkerAgent = new Agent($this->platform, 'gemini-2.5-flash');

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser("USER INTENT: $userIntent")
        );

        try {
            $result = $checkerAgent->call($messages);
            $content = $result->getContent();
            
            // Markdown-Blöcke entfernen
            $content = str_replace(['```json', '```'], '', $content);
            $content = trim($content);
            
            $decoded = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('JSON decode failed', [
                    'content' => $content,
                    'error' => json_last_error_msg()
                ]);
                return $this->getFallbackResponse($userIntent);
            }
            
            return $decoded;
            
        } catch (\Exception $e) {
            $this->logger->error('LLM Analysis failed', ['error' => $e->getMessage()]);
            return $this->getFallbackResponse($userIntent);
        }
    }

    /**
     * Fallback bei LLM-Fehlern
     */
    private function getFallbackResponse(string $userIntent): array
    {
        return [
            'has_capability' => false, 
            'missing_logic_description' => "Create a tool capable of handling: $userIntent"
        ];
    }

    /**
     * Fordert den DevAgent an (via ToolRequestor)
     */
    private function requestDynamicToolCreation(string $userIntent, string $techDescription): array
    {
        $devPrompt = <<<PROMPT
        Entwickle ein neues Symfony AI Tool (PHP Klasse).
        
        ANFORDERUNG (User Intent): $userIntent
        TECHNISCHE SPEZIFIKATION: $techDescription
        
        RICHTLINIEN:
        - Namespace: App\Tool
        - Nutze #[AsTool] Attribute
        - Implementiere __invoke
        - Beachte Strict Types und Error Handling
        PROMPT;

        return ($this->toolRequestor)($devPrompt);
    }

    private function loadAvailableToolDefinitions(): void
    {
        try {
            $tools = $this->toolbox->getTools();
            foreach ($tools as $tool) {
                $this->availableToolDefinitions[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to load tools', ['error' => $e->getMessage()]);
        }
    }
    
    public function getAvailableToolDefinitions(): array
    {
        return $this->availableToolDefinitions;
    }

    public function getAvailableTools(): array
    {
        return array_column($this->availableToolDefinitions, 'name');
    }
}
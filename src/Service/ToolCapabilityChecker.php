<?php
// src/Service/ToolCapabilityChecker.php

declare(strict_types=1);

namespace App\Service;

use App\Tool\ToolRequestor;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dynamic Tool Capability Checker
 * * Nutzt einen internen Agenten, um User-Intent gegen verfügbare Tools zu matchen
 * und bei Bedarf dynamisch neue Tools zu spezifizieren.
 */
final class ToolCapabilityChecker
{
    private array $availableToolDefinitions = [];

    public function __construct(
        #[Autowire(service: 'ai.fault_tolerant_toolbox.personal_assistent.inner')]
        private Toolbox $toolbox,
        
        // KORREKTUR: Wir injizieren die Platform statt eines nicht existierenden ChatModels
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
        // 1. LLM fragen: Haben wir das Tool schon?
        $analysis = $this->analyzeToolAvailability($userIntent);

        if ($analysis['has_tool'] ?? false) {
            $this->logger->info('Matching tool found', ['tool' => $analysis['tool_name']]);
            return ['status' => 'available', 'tool' => $analysis['tool_name']];
        }

        // 2. Wenn nicht: Tool dynamisch anfordern
        $this->logger->info('No matching tool found. Requesting creation.', ['intent' => $userIntent]);
        $techDescription = $analysis['missing_logic_description'] ?? "Tool logic for: $userIntent";
        
        return $this->requestDynamicToolCreation($userIntent, $techDescription);
    }

    /**
     * Erstellt einen Ad-Hoc Agenten, um zu prüfen, ob der Intent lösbar ist.
     */
    private function analyzeToolAvailability(string $userIntent): array
    {
        // Liste der Tools für den Prompt vorbereiten
        $toolDescriptions = json_encode($this->availableToolDefinitions, JSON_PRETTY_PRINT);

        // System Prompt definieren
        $systemPrompt = <<<PROMPT
        Du bist ein intelligenter System-Architekt für eine Symfony AI Anwendung.
        
        VERFÜGBARE TOOLS:
        $toolDescriptions
        
        AUFGABE:
        Prüfe, ob eines der verfügbaren Tools den untenstehenden User Intent funktional erfüllen kann.
        Achte auf die BESCHREIBUNG, nicht nur auf den Namen.
        
        ANTWORTE NUR IM JSON-FORMAT (ohne Markdown ```json ... ```):
        {
            "has_tool": boolean,
            "tool_name": "Name des Tools oder null",
            "reasoning": "Kurze Begründung",
            "missing_logic_description": "Falls has_tool false ist: Eine präzise technische Beschreibung für einen Entwickler, was das neue Tool tun muss."
        }
        PROMPT;

        // KORREKTUR: Wir nutzen die Agent Klasse direkt wie in der Doku "Basic Usage"
        // Wir nutzen hier ein schnelles Modell (Flash) für diese interne Logik-Prüfung
        $checkerAgent = new Agent($this->platform, 'gemini-2.5-flash');

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser("USER INTENT: $userIntent")
        );

        try {
            $result = $checkerAgent->call($messages);
            $content = $result->getContent();
            
            // Markdown-Code-Blöcke entfernen, falls das LLM welche sendet
            $content = str_replace(['```json', '```'], '', $content);
            
            return json_decode($content, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->error('LLM Analysis failed', ['error' => $e->getMessage()]);
            // Fallback: Wir nehmen an, wir haben das Tool nicht
            return [
                'has_tool' => false, 
                'missing_logic_description' => "Create a tool capable of handling: $userIntent"
            ];
        }
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
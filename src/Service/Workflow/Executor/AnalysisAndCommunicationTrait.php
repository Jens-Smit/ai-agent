<?php
// src/Service/Workflow/Executor/AnalysisAndCommunicationTrait.php

declare(strict_types=1);

namespace App\Service\Workflow\Executor;

use App\Entity\WorkflowStep;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

trait AnalysisAndCommunicationTrait
{
    /**
     * Führt eine Analyse durch
     */
    private function executeAnalysis(WorkflowStep $step, array $context, string $sessionId): array
    {
        $expectedFormat = $step->getExpectedOutputFormat();

        if ($expectedFormat && isset($expectedFormat['fields'])) {
            return $this->executeStructuredAnalysis($step, $context, $sessionId, $expectedFormat['fields']);
        }

        $prompt = sprintf(
            'Analysiere folgende Daten und %s: %s',
            $step->getDescription(),
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        return ['analysis' => $result->getContent()];
    }

    /**
     * Führt eine strukturierte Analyse durch
     */
    private function executeStructuredAnalysis(
        WorkflowStep $step,
        array $context,
        string $sessionId,
        array $requiredFields
    ): array {
        $fieldsList = implode(', ', array_keys($requiredFields));

        $prompt = sprintf(
            'Analysiere die folgenden Daten und %s.

            KRITISCH WICHTIG: 
            1. Antworte NUR mit einem gültigen JSON-Objekt
            2. Das JSON MUSS EXAKT diese Felder enthalten: %s
            3. Kein Text vor oder nach dem JSON
            4. Keine Markdown-Formatierung außer ```json Block

            Format:
            ```json
            {
            %s
            }
            ```

            Daten zur Analyse:
            %s',
            $step->getDescription(),
            $fieldsList,
            implode(",\n", array_map(fn($k) => "  \"$k\": \"<wert für $k>\"", array_keys($requiredFields))),
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        $content = $result->getContent();
        $structuredData = $this->extractStructuredJson($content, array_keys($requiredFields));

        $this->logger->info('Structured analysis completed', [
            'step' => $step->getStepNumber(),
            'extracted_fields' => array_keys($structuredData),
            'field_values' => $structuredData
        ]);

        return $structuredData;
    }

    /**
     * Führt eine Entscheidung durch
     */
    private function executeDecision(WorkflowStep $step, array $context, string $sessionId): array
    {
        $expectedFormat = $step->getExpectedOutputFormat();

        if ($expectedFormat && isset($expectedFormat['fields'])) {
            return $this->executeStructuredAnalysis($step, $context, $sessionId, $expectedFormat['fields']);
        }

        $prompt = sprintf(
            'Treffe folgende Entscheidung: %s. Basierend auf: %s',
            $step->getDescription(),
            json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $messages = new MessageBag(Message::ofUser($prompt));
        $result = $this->callAgentWithFallback($messages, $sessionId);

        return ['decision' => $result->getContent()];
    }

    /**
     * Führt eine Benachrichtigung durch
     */
    private function executeNotification(WorkflowStep $step, array $context, string $sessionId): array
    {
        $message = $this->resolveContextPlaceholders($step->getDescription(), $context);

        $this->statusService->addStatus($sessionId, '📧 ' . $message);

        return [
            'notification_sent' => true,
            'message' => $message
        ];
    }

    /**
     * Extrahiert strukturiertes JSON aus Agent-Antwort
     */
    private function extractStructuredJson(string $content, array $requiredFields): array
    {
        // Strategie 1: JSON in Code-Block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        }
        // Strategie 2: Erstes vollständiges JSON-Objekt
        elseif (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $content, $matches)) {
            $json = $matches[0];
        } else {
            $this->logger->warning('No JSON found, attempting key-value extraction', [
                'content_preview' => substr($content, 0, 200)
            ]);
            return $this->extractKeyValuePairs($content, $requiredFields);
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('JSON decode failed, falling back to key-value extraction', [
                'error' => json_last_error_msg(),
                'json_preview' => substr($json, 0, 200)
            ]);
            return $this->extractKeyValuePairs($content, $requiredFields);
        }

        // Validiere und ergänze fehlende Felder
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $this->logger->warning('Missing or empty required field, attempting extraction', [
                    'field' => $field
                ]);
                $extracted = $this->extractFieldFromText($content, $field);
                if ($extracted) {
                    $data[$field] = $extracted;
                }
            }
        }

        return $data;
    }

    /**
     * Extrahiert Key-Value-Paare aus Text
     */
    private function extractKeyValuePairs(string $content, array $requiredFields): array
    {
        $result = [];

        foreach ($requiredFields as $field) {
            $value = $this->extractFieldFromText($content, $field);
            $result[$field] = $value ?? '';
        }

        return $result;
    }

    /**
     * Extrahiert ein spezifisches Feld aus Text
     */
    private function extractFieldFromText(string $content, string $fieldName): ?string
    {
        // Pattern 1: "field_name": "value"
        if (preg_match('/"' . preg_quote($fieldName) . '"\s*:\s*"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }

        // Pattern 2: **Firmenname:** plusYou GmbH
        if (preg_match('/\*\*' . preg_quote(str_replace('_', ' ', $fieldName)) . '[:\*]*\s*([^\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        // Pattern 3: Firmenname: plusYou GmbH
        if (preg_match('/' . preg_quote(str_replace('_', ' ', $fieldName)) . '\s*:\s*([^\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        // Pattern 4: - Arbeitgeber: plusYou GmbH
        if (preg_match('/-\s*' . preg_quote(str_replace('_', ' ', $fieldName)) . '\s*:\s*([^\n]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Ruft den Agent mit Fallback-Logik auf
     */
    private function callAgentWithFallback(MessageBag $messages, string $sessionId): mixed
    {
        try {
            if ($this->useFlashLite) {
                if (!$this->flashLiteAgent) {
                    $this->flashLiteAgent = new \Symfony\AI\Agent\Agent(
                        $this->platform,
                        'gemini-2.0-flash-lite'
                    );

                    if ($toolbox = $this->agent->getToolbox()) {
                        $this->flashLiteAgent->setToolbox($toolbox);
                    }
                }

                $this->statusService->addStatus($sessionId, '🔄 Nutze Flash Lite (Fallback)');
                return $this->flashLiteAgent->call($messages);
            }

            $result = $this->agent->call($messages);
            $this->agentFailureCount = 0;
            return $result;

        } catch (\Throwable $e) {
            $this->agentFailureCount++;

            $this->logger->warning('Agent call failed', [
                'failure_count' => $this->agentFailureCount,
                'error' => $e->getMessage()
            ]);

            if ($this->agentFailureCount >= 3 && !$this->useFlashLite) {
                $this->useFlashLite = true;
                $this->statusService->addStatus($sessionId, '⚠️ Wechsle zu Flash Lite');
                sleep(2);
                return $this->callAgentWithFallback($messages, $sessionId);
            }

            throw $e;
        }
    }

    /**
     * Prüft ob ein Fehler vorübergehend ist
     */
    private function isTransientError(string $error): bool
    {
        $lowerError = strtolower($error);
        $transientPatterns = [
            'response does not contain',
            'rate limit',
            'timeout',
            '503',
            '429',
            '500',
            'temporarily unavailable'
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($lowerError, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
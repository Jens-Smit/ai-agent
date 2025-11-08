<?php

namespace App\Controller;

use App\Tool\CodeSaverTool; // Importiere das Tool
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire; // WICHTIG: Autowire hinzufügen
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class FileGeneratorController extends AbstractController
{
    private AgentProcessor $toolProcessor;
    private PlatformInterface $platform;
    private Toolbox $toolbox;
    private LoggerInterface $logger;
    private CodeSaverTool $codeSaverTool; // NEU: Property für das CodeSaverTool

    public function __construct(
        PlatformInterface $platform, 
        // Autowiring bleibt für die inneren Tools.
        #[Autowire(service: 'ai.fault_tolerant_toolbox.default.inner')] 
        Toolbox $toolbox,
        LoggerInterface $logger,
        CodeSaverTool $codeSaverTool // NEU: CodeSaverTool injizieren
    ) {
        $this->platform = $platform;
        $this->toolbox = $toolbox;
        $this->logger = $logger;
        $this->codeSaverTool = $codeSaverTool; // Speichern der Tool-Instanz
        
        // Setup AgentProcessor, der die Tool-Ausführung ermöglicht
        $this->toolProcessor = new AgentProcessor($toolbox);
    }

    #[Route('/api/generate-file', name: 'api_generate_file', methods: ['POST'])]
    public function generateFile(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $userPrompt = $data['prompt'] ?? null;

        if (!$userPrompt) {
            return $this->json(['error' => 'The "prompt" is missing in the request body.'], 400);
        }

        // <<< MODEL REMAINS FLASH >>>
        $model = 'gemini-2.5-flash'; 

        // Agent-Instanziierung mit Input- und Output-Prozessoren zur Aktivierung von Tools
        $agent = new Agent(
            $this->platform, 
            $model, 
            [$this->toolProcessor], // Input Processors aktivieren Tool-Logik
            [$this->toolProcessor]  // Output Processors verarbeiten Tool-Ergebnisse und Endantwort
        );

        // NEUER System Prompt: Erlaubt eine konversationelle Antwort, muss aber den Filenamen erwähnen.
        $systemPrompt = <<<PROMPT
            You are a file generation component. Your sole task is to generate the requested file content using the 'save_code_file' tool.
            
            1. Use the 'save_code_file' tool with the filename and content.
            2. After receiving the tool's result (SUCCESS or ERROR), provide a concise conversational summary to the user.
            3. In your summary, you MUST clearly mention the filename you created (e.g., 'index.html').
            PROMPT;

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt)
        );

        try {
            // Agent::call() führt den Tool-Calling-Loop automatisch aus.
            $result = $agent->call($messages);
            $finalContent = trim($result->getContent());

            // 1. NEUE Robuste Erfolgsprüfung: Suche nach dem Dateinamen, der in der finalen Antwort enthalten sein sollte.
            // Der Regex sucht nach einem Dateinamen (ohne Pfadtrenner) mit einer bekannten Dateiendung.
            $pathPattern = '/([\w\-]+\.(php|html|js|css|md|txt|json|yaml|xml))/i';
            
            // Match-Check: Sollte jetzt den Dateinamen finden, auch wenn er in Fülltext eingebettet ist.
            if (preg_match($pathPattern, $finalContent, $matches)) {
                 $filePath = $matches[1];
                 
                 return $this->json([
                    'status' => 'success',
                    'message' => 'File generation successful. File path was extracted from the AI response.',
                    'details' => sprintf("Filename: %s. The file was created on the server. AI Response: %s", $filePath, $finalContent),
                    'file_path' => $filePath, // Gibt den Pfad für die Client-Anzeige zurück
                ]);
            }
            
            // 2. Fallback: Warnung, wenn kein Pfad gefunden wird (trotz Fülltext).
            return $this->json([
                'status' => 'warning',
                'message' => 'The AI did not complete the file generation or the final output did not contain a recognizable file name.',
                'ai_response' => $finalContent,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Agent execution failed: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json([
                'error' => 'Agent execution failed.', 
                'details' => $e->getMessage(),
                'code' => $e->getCode(),
            ], 500);
        }
    }

    /**
     * NEUER ENDPUNKT ZUM TESTEN DER DATEIBERECHTIGUNGEN
     * Ruft das CodeSaverTool direkt mit statischem Inhalt auf.
     */
    #[Route('/api/test-file-write', name: 'api_test_file_write', methods: ['GET'])]
    public function testFileWrite(): JsonResponse
    {
        // Name der Testdatei
        $filename = 'manual_test_file.txt';
        // Inhalt der Testdatei
        $content = 'This file was written directly via the test endpoint. If this file exists in "generated_code", your web server has the correct write permissions.';
        
        $this->logger->info('Starting manual file write test via ' . $filename);

        try {
            // Rufen Sie das Tool direkt auf
            $result = $this->codeSaverTool->__invoke($filename, $content);

            if (str_contains($result, 'SUCCESS')) {
                return $this->json([
                    'status' => 'success',
                    'message' => 'Manual file creation successful.',
                    'details' => $result,
                    'file_path' => $filename,
                    'action' => 'Check the "generated_code" directory for the file: ' . $filename,
                ]);
            }

            // Gibt bei einem Fehler die Rückmeldung des Tools zurück (sollte 'ERROR' enthalten)
            return $this->json([
                'status' => 'error',
                'message' => 'Manual file creation failed.',
                'details' => $result,
                'action' => 'Check the Symfony logs for permission errors related to the "generated_code" directory.',
            ], 500);

        } catch (\Exception $e) {
            $this->logger->error('Manual file write failed with exception: ' . $e->getMessage());
            return $this->json([
                'error' => 'An exception occurred during manual file writing.', 
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
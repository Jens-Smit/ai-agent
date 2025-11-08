<?php

namespace App\Tool;

use Symfony\AI\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface; // Wichtig: Muss importiert werden!

#[AsTool(
    name: 'save_code_file',
    description: 'Saves the provided code content to a file with a given name in the generated_code directory.'
)]
final class CodeSaverTool
{
    // Die Basisadresse des Verzeichnisses, relativ zum Tool-Ordner.
    private const BASE_DIR = __DIR__.'/../../generated_code/';
    private LoggerInterface $logger;

    // KORREKTUR: LoggerInterface $logger als Argument hinzufügen
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $this->logger->info('CodeSaverTool initialized. Base Directory Check: ' . self::BASE_DIR); // Logging der Pfad-Prüfung

        // Erstellt das Verzeichnis, falls es noch nicht existiert
        if (!is_dir(self::BASE_DIR)) {
            $this->logger->warning('Base directory does not exist. Attempting to create: ' . self::BASE_DIR);
            
            // Wichtig: Prüfen, ob die Erstellung funktioniert
            if (!mkdir(self::BASE_DIR, 0777, true)) {
                $this->logger->error('Failed to create base directory due to permission or path issue: ' . self::BASE_DIR);
                // Hier wird der Fehler geloggt, falls Berechtigungen fehlen.
            } else {
                 $this->logger->info('Successfully created base directory: ' . self::BASE_DIR);
            }
        }
    }

    /**
     * Speichert Code-Inhalt in einer Datei.
     * @param string $filename Der Name der Datei (z.B. "index.html"). Muss eine gültige Endung haben.
     * @param string $content  Der Code-Inhalt, der gespeichert werden soll.
     */
    public function __invoke(
        #[With(pattern: '/^[^\\/]+\\.(html|js|php|yaml|json|css|md|txt|xml)$/i')]
        string $filename,
        string $content
    ): string {
        // basename() verhindert Pfad-Manipulationen (z.B. ../)
        $filepath = self::BASE_DIR . basename($filename);
        $this->logger->info('Attempting to write file: ' . $filepath); // Logging des Schreibvorgangs

        if (file_put_contents($filepath, $content) !== false) {
            $this->logger->info('Successfully wrote file: ' . $filepath);
            return "SUCCESS: Code was saved to file: " . basename($filename);
        }
        
        $this->logger->error('File write failed: ' . $filepath . '. Check directory permissions.');
        return "ERROR: Failed to save code to file: " . basename($filename);
    }
}
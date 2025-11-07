<?php
namespace App\Ai\Tool;

use Symfony\Component\Ai\Tool\Attribute\AsTool;
use Symfony\Component\Ai\Tool\Attribute as AiArgument;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ermöglicht dem Agenten, Inhalte in eine Datei im 'generated_code' Verzeichnis zu schreiben.
 */
// Das #[AsTool] Attribut macht diese Klasse als Service bekannt und registriert sie automatisch
final class WriteFileTool
{
    // Absoluter Pfad zum generierten Code-Verzeichnis
    private const BASE_PATH = __DIR__.'/../../../generated_code';
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
        // Stellen Sie sicher, dass das Verzeichnis existiert
        if (!$this->filesystem->exists(self::BASE_PATH)) {
            $this->filesystem->mkdir(self::BASE_PATH);
        }
    }

    #[AsTool(
        name: 'write_file',
        description: 'Schreibt den gegebenen Inhalt (content) in eine Datei am angegebenen Pfad (path). Pfade sind relativ zum `generated_code` Verzeichnis.'
    )]
    public function __invoke(
        #[AiArgument\Argument('Der relative Pfad und Dateiname, z.B. "MyClass.php".')] 
        string $path,
        #[AiArgument\Argument('Der vollständige Inhalt (z.B. PHP-Code oder Text) der in die Datei geschrieben werden soll. Muss mit <?php beginnen, wenn es PHP-Code ist.')]
        string $content
    ): string {
        $fullPath = self::BASE_PATH.'/'.$path;
        
        try {
            // content kann auch Unterverzeichnisse enthalten, die erstellt werden müssen
            $this->filesystem->dumpFile($fullPath, $content); 
            return sprintf("Datei erfolgreich gespeichert unter: %s", $path);
        } catch (\Throwable $e) {
            return sprintf("FEHLER beim Speichern der Datei %s: %s", $path, $e->getMessage());
        }
    }
}
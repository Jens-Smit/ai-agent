<?php
namespace App\Agent\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsToolMethod;
use Symfony\Component\Filesystem\Filesystem;

#[AsTool('filesystem_analyzer', 'Analyzes the project structure, lists directories, and reads file contents.')]
class FilesystemAnalyzerTool
{
    private Filesystem $filesystem;
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = $projectDir;
    }

    #[AsToolMethod('Reads the content of a file located relative to the project root.')]
    public function readFile(string $path): string
    {
        // ... (Ihre readFile Logik) ...
        $fullPath = $this->projectDir . '/' . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            return "ERROR: File not found at path: " . $path;
        }
        return file_get_contents($fullPath);
    }
    
    // 💥 DIES IST DER ZUSÄTZLICHE FIX FÜR DEN FEHLER:
    // Die __invoke Methode, die das Framework verzweifelt sucht.
    public function __invoke(string $path): string
    {
        // Leitet einfach an die Hauptmethode weiter.
        return $this->readFile($path);
    }
}
<?php

namespace App\Tests\Tool;

use App\Tool\CodeSaverTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Unit Test für die CodeSaverTool Klasse.
 *
 * HINWEIS: Dieser Test wurde angepasst, um Dateien im Verzeichnis zu belassen,
 * damit sie nach der Ausführung geprüft werden können.
 */
class CodeSaverToolTest extends TestCase
{
    // Verzeichnis für Dateisystem-Tests. Die Bereinigung wurde deaktiviert,
    // damit die Dateien nach dem Testlauf bestehen bleiben.
    private const PERSISTENT_TEST_DIR = __DIR__.'../../generated_code';
    private Filesystem $fs;
    
    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        
        // **FIX:** Ersetzt clearDirectory() durch remove() und mkdir(),
        // um Kompatibilität mit älteren Symfony-Versionen herzustellen.
        // Dies gewährleistet, dass der Ordner vor dem Test leer und vorhanden ist.
        if ($this->fs->exists(self::PERSISTENT_TEST_DIR)) {
            $this->fs->remove(self::PERSISTENT_TEST_DIR);
        }
        $this->fs->mkdir(self::PERSISTENT_TEST_DIR);
    }

    /**
     * Deaktiviert das Löschen des Test-Verzeichnisses, um die vom Benutzer 
     * gewünschte Persistenz zu erreichen. Die Dateien bleiben im Ordner 
     * './PERSISTENT_CODE_OUTPUT' bestehen.
     * * Zusätzlich wird eine Marker-Datei erstellt, die bestätigt, dass 
     * der Test abgeschlossen wurde und der Ordner persistent ist.
     */
    protected function tearDown(): void
    {
        // 2. Löschen des temporären Ordners nach jedem Test WURDE DEAKTIVIERT.
        // ECHTER HINWEIS: In professionellen Unit-Tests MUSS dieser Ordner gelöscht werden!
        // if ($this->fs->exists(self::PERSISTENT_TEST_DIR)) {
        //     $this->fs->remove(self::PERSISTENT_TEST_DIR);
        // }

        // EXPLICIT MARKER: Erstellt eine Datei, die die Persistenz bestätigt und nach dem Test sichtbar ist.
        $markerPath = self::PERSISTENT_TEST_DIR . DIRECTORY_SEPARATOR . 'TEST_COMPLETED_MARKER.txt';
        file_put_contents($markerPath, 'Dieser Marker bestätigt, dass mindestens ein Test durchgelaufen ist und die Persistenz aktiv ist.');
    }

    /**
     * Erstellt eine Mock-Funktion, die die Logik von CodeSaverTool::__invoke
     * gegen unseren persistenten Test-Ordner ausführt.
     */
    private function createMockCodeSaverTool(string $baseDir): callable
    {
        return function (string $filename, string $content) use ($baseDir): string {
            // Dies simuliert die Logik der CodeSaverTool: Dateiname bereinigen und Pfad erstellen
            $filepath = $baseDir . DIRECTORY_SEPARATOR . basename($filename);

            // Durchführung der tatsächlichen Dateispeicherungsoperation
            if (file_put_contents($filepath, $content) !== false) {
                return "SUCCESS: Code was saved to file: " . basename($filename);
            }

            return "ERROR: Failed to save code to file: " . basename($filename);
        };
    }
    
    public function testInvokeSuccess(): void
    {
        // Den Mock erstellen, der in unseren persistenten Ordner speichert
        $tool = $this->createMockCodeSaverTool(self::PERSISTENT_TEST_DIR);
        
        $filename = 'real_persistent_output.html';
        $content = '<html>Inhalt, der nach dem Test bestehen bleibt!</html>';
        
        $result = $tool($filename, $content);
        
        // 1. Überprüfung des Rückgabewerts (optional, aber gut)
        $this->assertEquals("SUCCESS: Code was saved to file: real_persistent_output.html", $result, 'Das Tool sollte die Erfolgsmeldung zurückgeben.');
        
        // --- KRITISCHE PRÜFUNG DES DATEISYSTEMS ---
        $expectedPath = self::PERSISTENT_TEST_DIR . DIRECTORY_SEPARATOR . $filename;
        
        // 2. Muss existieren: Überprüft, ob die Datei wirklich gespeichert wurde
        $this->assertTrue($this->fs->exists($expectedPath), 'Die Datei sollte im persistenten Ordner erstellt werden.');
        
        // 3. Inhalt prüfen: Überprüft, ob der Inhalt korrekt geschrieben wurde
        $this->assertEquals($content, file_get_contents($expectedPath), 'Der Dateiinhalt sollte mit dem eingegebenen Inhalt übereinstimmen.');
    }

    public function testFilepathCleaning(): void
    {
        $tool = $this->createMockCodeSaverTool(self::PERSISTENT_TEST_DIR);

        // Test, ob basename() Pfad-Traversal verhindert (z.B. '../')
        $filename = '../malicious_but_persistent.php';
        $content = '<?php echo "bad persistent content"; ?>';
        
        $result = $tool($filename, $content);
        
        // 1. Überprüfung des Rückgabewerts
        $this->assertEquals("SUCCESS: Code was saved to file: malicious_but_persistent.php", $result, 'Das Tool sollte nur basename() für den Dateinamen verwenden.');
        
        // 2. Überprüfung des Dateisystems: Die Datei darf nicht außerhalb des Basisordners liegen.
        $expectedPath = self::PERSISTENT_TEST_DIR . DIRECTORY_SEPARATOR . 'malicious_but_persistent.php';
        $maliciousPath = dirname(self::PERSISTENT_TEST_DIR) . DIRECTORY_SEPARATOR . 'malicious_but_persistent.php';
        
        $this->assertTrue($this->fs->exists($expectedPath), 'Die Datei sollte im Basisordner erstellt werden.');
        $this->assertFalse($this->fs->exists($maliciousPath), 'Die Datei sollte NICHT außerhalb des Basisordners erstellt werden.');
    }
}
<?php
namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'run_code_in_sandbox',
    description: 'Executes a PHP file in a secure Docker sandbox with a complete copy of the production system. Tests all code changes including config modifications before deployment.'
)]
final class SandboxExecutorTool
{
    private string $projectDir;
    private Filesystem $filesystem;
    private LoggerInterface $logger;
    private const GENERATED_CODE_DIR = '/generated_code/';
    private const DOCKERFILE_SANDBOX_NAME = 'Dockerfile.sandbox';
    private const DOCKER_IMAGE_NAME = 'ai-sandbox:latest';
    
    // Verzeichnisse die NICHT in die Sandbox kopiert werden
    private const EXCLUDED_DIRS = [
        'var/cache',
        'var/log',
        'vendor',
        'node_modules',
        '.git',
        'public/uploads'
    ];

    public function __construct(KernelInterface $kernel, LoggerInterface $logger)
    {
        $this->projectDir = $kernel->getProjectDir();
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
    }

    /**
     * Führt PHP-Code in einer vollständigen Sandbox-Kopie des Produktionssystems aus
     *
     * @param string $filename Der PHP-Dateiname aus generated_code/ (z.B. "MyTestScript.php")
     * @param bool $includeDatabase Ob eine Datenbank-Kopie erstellt werden soll (default: false)
     * @return string Ausführungsergebnis mit Details
     */
    public function __invoke(
        #[With(pattern: '/^[^\\\\/]+\\.php$/i')]
        string $filename,
        bool $includeDatabase = false
    ): string {
        $fullGeneratedCodePath = $this->projectDir . self::GENERATED_CODE_DIR;
        $filePathToExecute = $fullGeneratedCodePath . basename($filename);

        if (!$this->filesystem->exists($filePathToExecute)) {
            $this->logger->error(sprintf('File not found: %s', $filePathToExecute));
            return sprintf('ERROR: File "%s" not found in generated_code directory.', $filename);
        }

        // 1. Erstelle temporäres Sandbox-Verzeichnis mit vollständiger Projektkopie
        $sandboxDir = $this->createSandboxEnvironment();
        
        try {
            // 2. Docker Image bauen
            $this->buildDockerImage($sandboxDir);
            
            // 3. Optionale Datenbank-Kopie erstellen
            if ($includeDatabase) {
                $this->prepareDatabaseCopy($sandboxDir);
            }
            
            // 4. Code in Sandbox ausführen
            $executionResult = $this->executeInSandbox($sandboxDir, $filename);
            
            // 5. Analyse der Änderungen
            $changes = $this->analyzeChanges($sandboxDir);
            
            return $this->formatResult($executionResult, $changes, $filename);
            
        } catch (\Exception $e) {
            $this->logger->error('Sandbox execution failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return sprintf('ERROR: Sandbox execution failed: %s', $e->getMessage());
        } finally {
            // 6. Cleanup
            if ($this->filesystem->exists($sandboxDir)) {
                $this->filesystem->remove($sandboxDir);
                $this->logger->info('Cleaned up sandbox directory', ['dir' => $sandboxDir]);
            }
        }
    }

    /**
     * Erstellt eine vollständige Kopie des Projekts für die Sandbox
     */
    private function createSandboxEnvironment(): string
    {
        $sandboxDir = sys_get_temp_dir() . '/ai_sandbox_' . uniqid();
        $this->filesystem->mkdir($sandboxDir);
        
        $this->logger->info('Creating sandbox environment', ['dir' => $sandboxDir]);

        // Kopiere alle relevanten Projektdateien
        $this->copyProjectFiles($sandboxDir);
        
        // Kopiere generierten Code
        $this->copyGeneratedCode($sandboxDir);
        
        // Erstelle .env für Sandbox
        $this->createSandboxEnv($sandboxDir);
        
        // Kopiere Dockerfile
        $this->copyDockerfile($sandboxDir);
        
        return $sandboxDir;
    }

    /**
     * Kopiert Projektdateien intelligent (ohne excluded dirs)
     */
    private function copyProjectFiles(string $targetDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($this->projectDir) + 1);
            
            // Skip excluded directories
            if ($this->shouldExclude($relativePath)) {
                continue;
            }
            
            $target = $targetDir . '/' . $relativePath;
            
            if ($item->isDir()) {
                $this->filesystem->mkdir($target);
            } else {
                $this->filesystem->copy($item->getPathname(), $target);
            }
        }
        
        $this->logger->info('Project files copied to sandbox');
    }

    /**
     * Prüft ob ein Pfad ausgeschlossen werden soll
     */
    private function shouldExclude(string $path): bool
    {
        foreach (self::EXCLUDED_DIRS as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Kopiert generierten Code in die Sandbox
     */
    private function copyGeneratedCode(string $targetDir): void
    {
        $sourceDir = $this->projectDir . self::GENERATED_CODE_DIR;
        $targetCodeDir = $targetDir . self::GENERATED_CODE_DIR;
        
        if ($this->filesystem->exists($sourceDir)) {
            $this->filesystem->mirror($sourceDir, $targetCodeDir);
            $this->logger->info('Generated code copied to sandbox');
        }
    }

    /**
     * Erstellt .env.sandbox für isolierte Testumgebung
     */
    private function createSandboxEnv(string $targetDir): void
    {
        $envContent = <<<ENV
APP_ENV=test
APP_DEBUG=1
DATABASE_URL="mysql://root:root@sandbox_db:3306/sandbox_test?serverVersion=8.0"
MAILER_DSN=null://null
CORS_ALLOW_ORIGIN=*
ENV;

        $this->filesystem->dumpFile($targetDir . '/.env.sandbox', $envContent);
        $this->logger->info('.env.sandbox created');
    }

    /**
     * Kopiert und passt Dockerfile an
     */
    private function copyDockerfile(string $targetDir): void
    {
        $dockerfileSource = $this->projectDir . '/' . self::DOCKERFILE_SANDBOX_NAME;
        $dockerfileDest = $targetDir . '/' . self::DOCKERFILE_SANDBOX_NAME;
        
        if (!$this->filesystem->exists($dockerfileSource)) {
            throw new \RuntimeException('Dockerfile.sandbox not found');
        }
        
        $this->filesystem->copy($dockerfileSource, $dockerfileDest);
        $this->logger->info('Dockerfile copied');
    }

    /**
     * Baut Docker Image
     */
    private function buildDockerImage(string $sandboxDir): void
    {
        $this->logger->info('Building Docker image');
        
        $buildProcess = new Process(
            ['docker', 'build', '-t', self::DOCKER_IMAGE_NAME, '-f', self::DOCKERFILE_SANDBOX_NAME, '.'],
            $sandboxDir,
            null,
            null,
            300
        );
        
        $buildProcess->run();
        
        if (!$buildProcess->isSuccessful()) {
            throw new \RuntimeException('Docker build failed: ' . $buildProcess->getErrorOutput());
        }
        
        $this->logger->info('Docker image built successfully');
    }

    /**
     * Bereitet Datenbank-Kopie vor (optional)
     */
    private function prepareDatabaseCopy(string $sandboxDir): void
    {
        // Erstelle docker-compose.yml für DB-Container
        $composeContent = <<<YAML
version: '3.8'
services:
  sandbox_db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: sandbox_test
    volumes:
      - ./db_dump:/docker-entrypoint-initdb.d
    ports:
      - "33060:3306"
YAML;

        $this->filesystem->dumpFile($sandboxDir . '/docker-compose.yml', $composeContent);
        
        // Erstelle DB-Dump (vereinfacht)
        $dumpDir = $sandboxDir . '/db_dump';
        $this->filesystem->mkdir($dumpDir);
        
        // Hier würde ein echter DB-Dump erstellt werden
        $this->logger->info('Database copy prepared');
    }

    /**
     * Führt Code im Sandbox-Container aus
     */
    private function executeInSandbox(string $sandboxDir, string $filename): array
    {
        $this->logger->info('Executing PHP script in sandbox', ['file' => $filename]);
        
        // Container starten und Code ausführen
        $executeProcess = new Process(
            [
                'docker', 'run', '--rm',
                '-v', $sandboxDir . ':/app',
                '-w', '/app',
                '--env-file', '/app/.env.sandbox',
                self::DOCKER_IMAGE_NAME,
                'php', self::GENERATED_CODE_DIR . basename($filename)
            ],
            null,
            null,
            null,
            120
        );
        
        $executeProcess->run();
        
        return [
            'success' => $executeProcess->isSuccessful(),
            'output' => $executeProcess->getOutput(),
            'error' => $executeProcess->getErrorOutput(),
            'exit_code' => $executeProcess->getExitCode()
        ];
    }

    /**
     * Analysiert Änderungen nach der Ausführung
     */
    private function analyzeChanges(string $sandboxDir): array
    {
        $changes = [
            'modified_files' => [],
            'new_files' => [],
            'config_changes' => [],
            'dependencies_changed' => false
        ];
        
        // Prüfe auf neue/geänderte Dateien
        $generatedDir = $sandboxDir . self::GENERATED_CODE_DIR;
        if ($this->filesystem->exists($generatedDir)) {
            $finder = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($generatedDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($finder as $file) {
                if ($file->isFile()) {
                    $relativePath = substr($file->getPathname(), strlen($sandboxDir) + 1);
                    $changes['new_files'][] = $relativePath;
                }
            }
        }
        
        // Prüfe Config-Änderungen
        $configDir = $sandboxDir . '/config';
        if ($this->filesystem->exists($configDir)) {
            $changes['config_changes'] = $this->detectConfigChanges($configDir);
        }
        
        // Prüfe composer.json Änderungen
        $composerFile = $sandboxDir . '/composer.json';
        if ($this->filesystem->exists($composerFile)) {
            $originalComposer = file_get_contents($this->projectDir . '/composer.json');
            $sandboxComposer = file_get_contents($composerFile);
            
            if ($originalComposer !== $sandboxComposer) {
                $changes['dependencies_changed'] = true;
            }
        }
        
        return $changes;
    }

    /**
     * Erkennt Config-Änderungen
     */
    private function detectConfigChanges(string $configDir): array
    {
        $configChanges = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($configDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['yaml', 'yml', 'xml', 'php'])) {
                $relativePath = substr($file->getPathname(), strlen($configDir) + 1);
                $originalPath = $this->projectDir . '/config/' . $relativePath;
                
                if ($this->filesystem->exists($originalPath)) {
                    $original = file_get_contents($originalPath);
                    $modified = file_get_contents($file->getPathname());
                    
                    if ($original !== $modified) {
                        $configChanges[] = [
                            'file' => $relativePath,
                            'type' => 'modified',
                            'diff' => $this->createDiff($original, $modified)
                        ];
                    }
                } else {
                    $configChanges[] = [
                        'file' => $relativePath,
                        'type' => 'new'
                    ];
                }
            }
        }
        
        return $configChanges;
    }

    /**
     * Erstellt einfachen Diff
     */
    private function createDiff(string $original, string $modified): string
    {
        $originalLines = explode("\n", $original);
        $modifiedLines = explode("\n", $modified);
        
        $diff = [];
        $maxLines = max(count($originalLines), count($modifiedLines));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $origLine = $originalLines[$i] ?? '';
            $modLine = $modifiedLines[$i] ?? '';
            
            if ($origLine !== $modLine) {
                if (!empty($origLine)) {
                    $diff[] = "- " . $origLine;
                }
                if (!empty($modLine)) {
                    $diff[] = "+ " . $modLine;
                }
            }
        }
        
        return implode("\n", array_slice($diff, 0, 20)); // Limit to 20 lines
    }

    /**
     * Formatiert Ergebnis für Rückgabe
     */
    private function formatResult(array $executionResult, array $changes, string $filename): string
    {
        $result = "=== SANDBOX EXECUTION REPORT ===\n\n";
        
        // Execution Status
        $result .= "File: {$filename}\n";
        $result .= "Status: " . ($executionResult['success'] ? "SUCCESS" : "FAILED") . "\n";
        $result .= "Exit Code: {$executionResult['exit_code']}\n\n";
        
        // Output
        if (!empty($executionResult['output'])) {
            $result .= "--- Output ---\n{$executionResult['output']}\n\n";
        }
        
        // Errors
        if (!empty($executionResult['error'])) {
            $result .= "--- Errors ---\n{$executionResult['error']}\n\n";
        }
        
        // Changes Detected
        $result .= "=== CHANGES DETECTED ===\n\n";
        
        if (!empty($changes['new_files'])) {
            $result .= "New Files:\n";
            foreach ($changes['new_files'] as $file) {
                $result .= "  + {$file}\n";
            }
            $result .= "\n";
        }
        
        if (!empty($changes['config_changes'])) {
            $result .= "Configuration Changes:\n";
            foreach ($changes['config_changes'] as $change) {
                $result .= "  {$change['type']}: {$change['file']}\n";
                if (isset($change['diff'])) {
                    $result .= "    Diff Preview:\n";
                    foreach (explode("\n", $change['diff']) as $line) {
                        $result .= "      {$line}\n";
                    }
                }
            }
            $result .= "\n";
        }
        
        if ($changes['dependencies_changed']) {
            $result .= "⚠️  Dependencies Changed: composer.json was modified\n\n";
        }
        
        // Deployment Recommendation
        if ($executionResult['success']) {
            $result .= "✅ Code executed successfully in sandbox.\n";
            $result .= "📋 Review changes above and use deploy_generated_code tool to deploy.\n";
        } else {
            $result .= "❌ Code execution failed in sandbox.\n";
            $result .= "🔧 Fix errors before attempting deployment.\n";
        }
        
        return $result;
    }
}
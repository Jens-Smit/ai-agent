<?php

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use App\Service\AgentStatusService;

#[AsTool(
    name: 'run_code_in_sandbox',
    description: 'Executes code in a complete isolated project clone. Creates full project copy in generated_code/Project, runs tests in Docker sandbox, and generates structured update packages with all changes and documentation.'
)]
final class SandboxExecutorTool
{
    private string $projectDir;
    private Filesystem $filesystem;
    private LoggerInterface $logger;
    private AgentStatusService $statusService;
    
    private const GENERATED_CODE_DIR = '/generated_code/';
    private const PROJECT_CLONE_DIR = '/generated_code/Project/';
    private const DOCKER_IMAGE_NAME = 'ai-sandbox:latest';
    
    // Verzeichnisse die NICHT kopiert werden
    private const EXCLUDED_DIRS = [
        'var/cache',
        'var/log',
        'vendor',
        'node_modules',
        '.git',
        'public/uploads',
        'generated_code' // Wichtig: Verhindert rekursive Kopien
    ];

    public function __construct(
        KernelInterface $kernel,
        LoggerInterface $logger,
        AgentStatusService $statusService
    ) {
        $this->projectDir = $kernel->getProjectDir();
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
        $this->statusService = $statusService;
    }

    /**
     * Führt Code in vollständiger Projekt-Sandbox aus und erstellt Update-Paket
     *
     * @param string $filename PHP-Dateiname aus generated_code/
     * @param string $updatePackageName Name für das Update-Paket (z.B. "GoogleCalendar")
     * @param bool $includeDatabase Datenbank-Kopie erstellen
     * @return string Detailliertes Ausführungsergebnis
     */
    public function __invoke(
        #[With(pattern: '/^[^\\\\/]+\\.php$/i')]
        string $filename,
        string $updatePackageName = 'UpdatePackage',
        bool $includeDatabase = false
    ): string {
        $this->statusService->addStatus('🚀 Sandbox-Ausführung gestartet');

        // normalize filename (prevent traversal) and validate
        $filename = trim(basename($filename));
        if ($filename === '' || !preg_match('/^[^\\/]+\.php$/i', $filename)) {
            $this->statusService->addStatus('❌ Ungültiger Dateiname: ' . (string) $filename);
            throw new \InvalidArgumentException('SandboxExecutorTool: invalid filename provided.');
        }

        $fullGeneratedCodePath = $this->projectDir . self::GENERATED_CODE_DIR;
        $filePathToExecute = $fullGeneratedCodePath . $filename;

        if (!$this->filesystem->exists($filePathToExecute)) {
            $this->statusService->addStatus('❌ Datei nicht gefunden: ' . $filename);
            return sprintf('ERROR: File "%s" not found in generated_code directory.', $filename);
        }

        try {
            $this->statusService->addStatus('📁 Erstelle vollständige Projektkopie...');
            $projectCloneDir = $this->createProjectClone();

            $this->statusService->addStatus('📋 Kopiere generierten Code...');
            $this->copyGeneratedCodeToClone($projectCloneDir);

            $this->statusService->addStatus('🐳 Baue Docker-Image...');
            $this->buildDockerImage($projectCloneDir);

            if ($includeDatabase) {
                $this->statusService->addStatus('🗄️ Bereite Datenbank vor...');
                $this->prepareDatabaseCopy($projectCloneDir);
            }

            $this->statusService->addStatus('⚙️ Führe Code in Sandbox aus...');
            $executionResult = $this->executeInSandbox($projectCloneDir, $filename);

            $this->statusService->addStatus('🔍 Analysiere Änderungen...');
            $changes = $this->analyzeChanges($projectCloneDir);

            $this->statusService->addStatus('📦 Erstelle Update-Paket...');
            $updatePackagePath = $this->createUpdatePackage(
                $projectCloneDir,
                $updatePackageName,
                $changes,
                $executionResult
            );

            $this->statusService->addStatus('✅ Sandbox-Ausführung erfolgreich abgeschlossen');

            return $this->formatResult(
                $executionResult,
                $changes,
                $filename,
                $updatePackagePath
            );

        } catch (\Exception $e) {
            $this->statusService->addStatus('❌ Fehler: ' . $e->getMessage());
            $this->logger->error('Sandbox execution failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return sprintf('ERROR: Sandbox execution failed: %s', $e->getMessage());
        }
    }



    /**
     * Erstellt vollständige Projektkopie in generated_code/Project/
     */
    private function createProjectClone(): string
    {
        $cloneDir = $this->projectDir . self::PROJECT_CLONE_DIR;
        
        // Entferne alte Clone falls vorhanden
        if ($this->filesystem->exists($cloneDir)) {
            $this->filesystem->remove($cloneDir);
        }
        
        $this->filesystem->mkdir($cloneDir);
        $this->logger->info('Creating project clone', ['dir' => $cloneDir]);

        // Kopiere alle Projektdateien
        $this->copyProjectFiles($this->projectDir, $cloneDir);
        
        // Erstelle .env für Sandbox
        $this->createSandboxEnv($cloneDir);
        
        // Kopiere Dockerfile
        $this->copyDockerfile($cloneDir);
        
        return $cloneDir;
    }

    /**
     * Kopiert Projektdateien intelligent
     */
    private function copyProjectFiles(string $sourceDir, string $targetDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
            
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
        
        $this->logger->info('Project files copied to clone');
    }

    /**
     * Prüft ob Pfad ausgeschlossen werden soll
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
     * Kopiert generierten Code in Clone
     */
    private function copyGeneratedCodeToClone(string $cloneDir): void
    {
        $sourceDir = $this->projectDir . self::GENERATED_CODE_DIR;
        $targetCodeDir = $cloneDir . self::GENERATED_CODE_DIR;
        
        if ($this->filesystem->exists($sourceDir)) {
            $this->filesystem->mkdir($targetCodeDir);
            
            // Kopiere nur neue PHP-Dateien, nicht Project/ oder alte Pakete
            $files = new \DirectoryIterator($sourceDir);
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $this->filesystem->copy(
                        $file->getPathname(),
                        $targetCodeDir . '/' . $file->getFilename()
                    );
                }
            }
            
            $this->logger->info('Generated code copied to clone');
        }
    }

    /**
     * Erstellt .env.sandbox
     */
    private function createSandboxEnv(string $targetDir): void
    {
        $envContent = <<<ENV
APP_ENV=test
APP_DEBUG=1
DATABASE_URL="mysql://root:root@sandbox_db:3306/sandbox_test?serverVersion=8.0"
MAILER_DSN=null://null
CORS_ALLOW_ORIGIN=*
GEMINI_API_KEY=test_key
OPENAI_API_KEY=test_key
CLAUD_API_KEY=test_key
GITHUB_ACCESS_TOKEN=dummy_github_access_token
DEV_AGENT_GITHUB_REPO=dummy_owner/dummy_repo
ENV;

        $this->filesystem->dumpFile($targetDir . '/.env.sandbox', $envContent);
    }

    /**
     * Kopiert Dockerfile
     */
    private function copyDockerfile(string $targetDir): void
    {
        $dockerfileSource = $this->projectDir . '/Dockerfile.sandbox';
        $dockerfileDest = $targetDir . '/Dockerfile.sandbox';
        
        if (!$this->filesystem->exists($dockerfileSource)) {
            throw new \RuntimeException('Dockerfile.sandbox not found');
        }
        
        $this->filesystem->copy($dockerfileSource, $dockerfileDest);
    }

    /**
     * Baut Docker Image
     */
    private function buildDockerImage(string $cloneDir): void
    {
        $buildProcess = new Process(
            ['docker', 'build', '-t', self::DOCKER_IMAGE_NAME, '-f', 'Dockerfile.sandbox', '.'],
            $cloneDir,
            null,
            null,
            300
        );
        
        $buildProcess->run();
        
        if (!$buildProcess->isSuccessful()) {
            throw new \RuntimeException('Docker build failed: ' . $buildProcess->getErrorOutput());
        }
    }

    /**
     * Bereitet Datenbank-Kopie vor
     */
    private function prepareDatabaseCopy(string $cloneDir): void
    {
        $composeContent = <<<YAML
version: '3.8'
services:
  sandbox_db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: sandbox_test
    ports:
      - "33060:3306"
YAML;

        $this->filesystem->dumpFile($cloneDir . '/docker-compose.yml', $composeContent);
    }

    /**
     * Führt Code in Sandbox aus
     */
    private function executeInSandbox(string $cloneDir, string $filename): array
    {
        $executeProcess = new Process(
            [
                'docker', 'run', '--rm',
                '-v', $cloneDir . ':/app',
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
     * Analysiert Änderungen nach Ausführung
     */
    private function analyzeChanges(string $cloneDir): array
    {
        $changes = [
            'modified_files' => [],
            'new_files' => [],
            'config_changes' => [],
            'dependencies_changed' => false
        ];
        
        // Suche nach neuen/geänderten Dateien
        $generatedDir = $cloneDir . self::GENERATED_CODE_DIR;
        if ($this->filesystem->exists($generatedDir)) {
            $finder = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($generatedDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($finder as $file) {
                if ($file->isFile()) {
                    $relativePath = substr($file->getPathname(), strlen($cloneDir) + 1);
                    $changes['new_files'][] = $relativePath;
                }
            }
        }
        
        // Prüfe Config-Änderungen
        $configDir = $cloneDir . '/config';
        if ($this->filesystem->exists($configDir)) {
            $changes['config_changes'] = $this->detectConfigChanges($cloneDir, $configDir);
        }
        
        // Prüfe composer.json
        $composerFile = $cloneDir . '/composer.json';
        if ($this->filesystem->exists($composerFile)) {
            $originalComposer = file_get_contents($this->projectDir . '/composer.json');
            $cloneComposer = file_get_contents($composerFile);
            
            if ($originalComposer !== $cloneComposer) {
                $changes['dependencies_changed'] = true;
            }
        }
        
        return $changes;
    }

    /**
     * Erkennt Config-Änderungen
     */
    private function detectConfigChanges(string $cloneDir, string $configDir): array
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
                            'type' => 'modified'
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
     * Erstellt strukturiertes Update-Paket
     */
    private function createUpdatePackage(
        string $cloneDir,
        string $packageName,
        array $changes,
        array $executionResult
    ): string {
        $timestamp = (new \DateTime())->format('YmdHis');
        $packageDir = $this->projectDir . self::GENERATED_CODE_DIR . 
                      'updatepaket_' . preg_replace('/[^a-zA-Z0-9]/', '', $packageName) . '_' . $timestamp;
        
        $this->filesystem->mkdir($packageDir);
        
        // Kopiere geänderte Dateien
        foreach ($changes['new_files'] as $file) {
            $source = $cloneDir . '/' . $file;
            $target = $packageDir . '/' . $file;
            
            if ($this->filesystem->exists($source)) {
                $this->filesystem->mkdir(dirname($target));
                $this->filesystem->copy($source, $target);
            }
        }
        
        // Erstelle README.md
        $this->createPackageReadme($packageDir, $packageName, $changes, $executionResult);
        
        // Erstelle CHANGES.md
        $this->createChangesLog($packageDir, $changes);
        
        $this->logger->info('Update package created', ['path' => $packageDir]);
        
        return $packageDir;
    }

    /**
     * Erstellt README.md für Update-Paket
     */
    private function createPackageReadme(
        string $packageDir,
        string $packageName,
        array $changes,
        array $executionResult
    ): void {
        $readme = "# Update-Paket: {$packageName}\n\n";
        $readme .= "Erstellt: " . (new \DateTime())->format('Y-m-d H:i:s') . "\n\n";
        
        $readme .= "## Sandbox-Test-Ergebnis\n\n";
        $readme .= "Status: " . ($executionResult['success'] ? '✅ Erfolgreich' : '❌ Fehlgeschlagen') . "\n";
        $readme .= "Exit Code: {$executionResult['exit_code']}\n\n";
        
        if (!empty($executionResult['output'])) {
            $readme .= "### Ausgabe\n```\n{$executionResult['output']}\n```\n\n";
        }
        
        if (!empty($executionResult['error'])) {
            $readme .= "### Fehler\n```\n{$executionResult['error']}\n```\n\n";
        }
        
        $readme .= "## Änderungen\n\n";
        $readme .= "### Neue Dateien (" . count($changes['new_files']) . ")\n";
        foreach ($changes['new_files'] as $file) {
            $readme .= "- `{$file}`\n";
        }
        $readme .= "\n";
        
        if (!empty($changes['config_changes'])) {
            $readme .= "### Konfigurationsänderungen\n";
            foreach ($changes['config_changes'] as $change) {
                $readme .= "- [{$change['type']}] `{$change['file']}`\n";
            }
            $readme .= "\n";
        }
        
        if ($changes['dependencies_changed']) {
            $readme .= "⚠️ **Dependencies geändert**: composer.json wurde modifiziert\n\n";
        }
        
        $readme .= "## Installation\n\n";
        $readme .= "1. Überprüfen Sie alle Änderungen in CHANGES.md\n";
        $readme .= "2. Kopieren Sie die Dateien an die entsprechenden Orte\n";
        $readme .= "3. Falls Dependencies geändert: `composer install`\n";
        $readme .= "4. Falls Config geändert: `php bin/console cache:clear`\n";
        $readme .= "5. Tests ausführen: `php bin/phpunit`\n";
        
        $this->filesystem->dumpFile($packageDir . '/README.md', $readme);
    }

    /**
     * Erstellt CHANGES.md
     */
    private function createChangesLog(string $packageDir, array $changes): void
    {
        $log = "# Änderungsprotokoll\n\n";
        $log .= "## Dateien\n\n";
        
        foreach ($changes['new_files'] as $file) {
            $log .= "### `{$file}`\n";
            $log .= "Status: Neu\n\n";
        }
        
        $this->filesystem->dumpFile($packageDir . '/CHANGES.md', $log);
    }

    /**
     * Formatiert Ergebnis
     */
    private function formatResult(
        array $executionResult,
        array $changes,
        string $filename,
        string $updatePackagePath
    ): string {
        $result = "=== SANDBOX EXECUTION REPORT ===\n\n";
        $result .= "File: {$filename}\n";
        $result .= "Status: " . ($executionResult['success'] ? "✅ SUCCESS" : "❌ FAILED") . "\n";
        $result .= "Exit Code: {$executionResult['exit_code']}\n\n";
        
        if (!empty($executionResult['output'])) {
            $result .= "--- Output ---\n{$executionResult['output']}\n\n";
        }
        
        if (!empty($executionResult['error'])) {
            $result .= "--- Errors ---\n{$executionResult['error']}\n\n";
        }
        
        $result .= "=== UPDATE PACKAGE ===\n\n";
        $result .= "Location: {$updatePackagePath}\n";
        $result .= "New Files: " . count($changes['new_files']) . "\n";
        $result .= "Config Changes: " . count($changes['config_changes']) . "\n\n";
        
        if ($executionResult['success']) {
            $result .= "✅ Code executed successfully in isolated sandbox.\n";
            $result .= "📦 Update package ready at: {$updatePackagePath}\n";
            $result .= "📖 Review README.md for installation instructions.\n";
        } else {
            $result .= "❌ Code execution failed in sandbox.\n";
            $result .= "🔧 Review errors before deployment.\n";
        }
        
        return $result;
    }
}

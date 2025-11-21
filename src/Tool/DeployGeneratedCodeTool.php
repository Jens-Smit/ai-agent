<?php
namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

#[AsTool(
    name: 'deploy_generated_code',
    description: 'Creates a comprehensive, safe deployment package with rollback capability. Includes config changes, dependency updates, and database migrations. Requires explicit user approval.'
)]
final class DeployGeneratedCodeTool
{
    private string $projectDir;
    private Filesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(KernelInterface $kernel, LoggerInterface $logger)
    {
        $this->projectDir = $kernel->getProjectDir();
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
    }

    /**
     * Erstellt ein sicheres Deployment-Paket mit Rollback-Fähigkeit.
     *
     * @param array<array{source_file: string, target_path: string, type?: string}> $filesToDeploy Ein Array von Dateinformationen zum Bereitstellen.
     * @param bool $createBackup Erstelle Backup vor Deployment (empfohlen).
     * @param bool $runTests Führe Tests nach Deployment aus.
     * @param string $sourceBaseDir Der Basisverzeichnis-Pfad, in dem die zu bereitstellenden Dateien liegen (z.B. generated_code/timestamp_uniqid/).
     * @return string Deployment-Anweisungen und Skript-Pfad.
     */
    public function __invoke(
        array $filesToDeploy,
        bool $createBackup = true,
        bool $runTests = true,
        string $sourceBaseDir = __DIR__ . '/../../generated_code/' // New parameter with default
    ): string {
        // Ensure sourceBaseDir ends with a slash
        $sourceBaseDir = rtrim($sourceBaseDir, '/').'/' ;

        if (!$this->filesystem->exists($sourceBaseDir)) {
            return sprintf('ERROR: Source directory "%s" does not exist.', $sourceBaseDir);
        }

        if (empty($filesToDeploy)) {
            return 'WARNING: No files specified for deployment.';
        }

        $timestamp = (new \DateTime())->format('YmdHis');
        
        // Erstelle Deployment-Verzeichnis im Haupt-generated_code/deployments/ Verzeichnis
        $baseGeneratedCodePath = $this->projectDir . '/generated_code/';
        if (!$this->filesystem->exists($baseGeneratedCodePath)) {
             $this->filesystem->mkdir($baseGeneratedCodePath, 0777, true);
        }
        $deploymentDir = $baseGeneratedCodePath . 'deployments/' . $timestamp;
        $this->filesystem->mkdir($deploymentDir);

        try {
            // 1. Validiere alle Dateien
            $validation = $this->validateDeployment($filesToDeploy, $sourceBaseDir);
            if (!$validation['valid']) {
                return $this->formatValidationErrors($validation['errors']);
            }

            // 2. Analysiere Änderungen
            $analysis = $this->analyzeChanges($filesToDeploy);
            
            // 3. Erstelle Deployment-Manifest
            $manifest = $this->createManifest($filesToDeploy, $analysis, $timestamp, $sourceBaseDir);
            $this->filesystem->dumpFile(
                $deploymentDir . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT)
            );

            // 4. Erstelle Backup-Skript
            if ($createBackup) {
                $this->createBackupScript($deploymentDir, $manifest);
            }

            // 5. Erstelle Deployment-Skript
            $deployScript = $this->createDeploymentScript(
                $deploymentDir,
                $manifest,
                $createBackup,
                $runTests,
                $sourceBaseDir // Pass the sourceBaseDir to the script creation
            );

            // 6. Erstelle Rollback-Skript
            $this->createRollbackScript($deploymentDir, $manifest);

            // 7. Erstelle README mit Anweisungen
            $this->createDeploymentReadme($deploymentDir, $manifest, $timestamp);

            return $this->formatSuccessResponse($deploymentDir, $manifest, $timestamp);

        } catch (\Exception $e) {
            $this->logger->error('Deployment preparation failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return sprintf('ERROR: Deployment preparation failed: %s', $e->getMessage());
        }
    }

    /**
     * Validiert Deployment-Anfrage
     */
    private function validateDeployment(array $filesToDeploy, string $sourceBaseDir): array
    {
        $errors = [];

        foreach ($filesToDeploy as $index => $fileInfo) {
            $sourceFile = $fileInfo['source_file'] ?? null;
            $targetPath = $fileInfo['target_path'] ?? null;

            if (!$sourceFile || !$targetPath) {
                $errors[] = "Entry {$index}: Missing source_file or target_path";
                continue;
            }

            // Validiere Source: Muss ein Dateiname sein, kein Pfad-Traversal
            if (basename($sourceFile) !== $sourceFile) {
                $errors[] = "Source '{$sourceFile}': Path traversal detected in source_file";
                continue;
            }

            $fullSourcePath = $sourceBaseDir . $sourceFile;
            if (!$this->filesystem->exists($fullSourcePath)) {
                $errors[] = "Source '{$sourceFile}': File not found at '{$fullSourcePath}'";
                continue;
            }

            // Validiere Target: Darf nicht außerhalb des Projekts auflösen
            $fullTargetPath = $this->projectDir . '/' . ltrim($targetPath, '/');
            // Realpath ist wichtig, um Symlinks und ../ zu berücksichtigen
            $resolvedTargetDir = realpath(dirname($fullTargetPath));
            $projectRoot = realpath($this->projectDir);

            if ($resolvedTargetDir === false || !str_starts_with($resolvedTargetDir, $projectRoot)) {
                $errors[] = "Target '{$targetPath}': Resolves outside project root ({$resolvedTargetDir})";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Analysiert Änderungen für Deployment
     */
    private function analyzeChanges(array $filesToDeploy): array
    {
        $analysis = [
            'code_files' => [],
            'config_files' => [],
            'test_files' => [],
            'migration_files' => [],
            'env_files' => [], // New category for .env files
            'requires_composer_update' => false,
            'requires_cache_clear' => false,
            'requires_migration' => false
        ];

        foreach ($filesToDeploy as $fileInfo) {
            $targetPath = $fileInfo['target_path'];
            $type = $fileInfo['type'] ?? $this->detectFileType($targetPath);

            switch ($type) {
                case 'config':
                    $analysis['config_files'][] = $targetPath;
                    $analysis['requires_cache_clear'] = true;
                    break;
                case 'migration':
                    $analysis['migration_files'][] = $targetPath;
                    $analysis['requires_migration'] = true;
                    break;
                case 'test':
                    $analysis['test_files'][] = $targetPath;
                    break;
                case 'env': // Handle .env files
                    $analysis['env_files'][] = $targetPath;
                    // .env changes don't necessarily require cache clear, but good to note
                    break;
                case 'composer':
                    $analysis['requires_composer_update'] = true;
                    break;
                default:
                    $analysis['code_files'][] = $targetPath;
            }
        }

        return $analysis;
    }

    /**
     * Erkennt Dateityp anhand des Pfads
     */
    private function detectFileType(string $path): string
    {
        if (str_contains($path, 'config/')) return 'config';
        if (str_contains($path, 'migrations/')) return 'migration';
        if (str_contains($path, 'tests/')) return 'test';
        if (str_contains($path, 'composer.json')) return 'composer';
        if (str_ends_with($path, '.env')) return 'env'; // Specific check for .env
        return 'code';
    }

    /**
     * Erstellt Deployment-Manifest
     */
    private function createManifest(array $filesToDeploy, array $analysis, string $timestamp, string $sourceBaseDir): array
    {
        return [
            'version' => '1.0',
            'timestamp' => $timestamp,
            'files' => array_map(function ($f) {
                    // Fehlt "type"? Dann automatisch erkennen
                    $f['type'] = $f['type'] ?? $this->detectFileType($f['target_path']);
                    return $f;
                }, $filesToDeploy),
            'analysis' => $analysis,
            'checksums' => $this->calculateChecksums($filesToDeploy, $sourceBaseDir),
            'php_version' => PHP_VERSION,
            'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION
        ];
    }

    /**
     * Berechnet Checksums für Dateien
     */
    private function calculateChecksums(array $filesToDeploy, string $sourceBaseDir): array
    {
        $checksums = [];

        foreach ($filesToDeploy as $fileInfo) {
            $sourceFile = $fileInfo['source_file'];
            $fullPath = $sourceBaseDir . $sourceFile;
            
            if ($this->filesystem->exists($fullPath) && is_file($fullPath)) { // Ensure it's a file
                $checksums[$sourceFile] = hash_file('sha256', $fullPath);
            } else {
                $this->logger->warning('Could not calculate checksum for non-existent or directory file.', ['path' => $fullPath]);
            }
        }

        return $checksums;
    }

    /**
     * Erstellt Backup-Skript
     */
    private function createBackupScript(string $deploymentDir, array $manifest): void
    {
        $script = "#!/bin/bash\n\n";
        $script .= "# Backup Script - Created " . date('Y-m-d H:i:s') . "\n";
        $script .= "# This script backs up files before deployment\n\n";
        $script .= "set -e\n\n";
        
        $backupDir = "\$1";
        $script .= "BACKUP_DIR=\"{$backupDir}\"\n";
        $script .= "PROJECT_ROOT=\"" . $this->projectDir . "\"\n\n";
        
        $script .= "mkdir -p \"\$BACKUP_DIR\"\n\n";
        $script .= "echo \"Creating backup in \$BACKUP_DIR\"\n\n";

        foreach ($manifest['files'] as $fileInfo) {
            $targetPath = $fileInfo['target_path'];
            $fullPath = "\$PROJECT_ROOT/" . ltrim($targetPath, '/');
            
            $script .= "if [ -f \"{$fullPath}\" ]; then\n";
            $script .= "    mkdir -p \"\$BACKUP_DIR/$(dirname {$targetPath})\"\n";
            $script .= "    cp \"{$fullPath}\" \"\$BACKUP_DIR/{$targetPath}\"\n";
            $script .= "    echo \"Backed up: {$targetPath}\"\n";
            $script .= "fi\n\n";
        }

        $script .= "echo \"Backup completed successfully\"\n";

        $scriptPath = $deploymentDir . '/01_backup.sh';
        $this->filesystem->dumpFile($scriptPath, $script);
        $this->filesystem->chmod($scriptPath, 0755);
    }

    /**
     * Erstellt Deployment-Skript
     */
    private function createDeploymentScript(
        string $deploymentDir,
        array $manifest,
        bool $createBackup,
        bool $runTests,
        string $sourceBaseDir // Added sourceBaseDir here
    ): string {
        $script = "#!/bin/bash\n\n";
        $script .= "# Deployment Script - Created " . date('Y-m-d H:i:s') . "\n";
        $script .= "# ⚠️  REVIEW THIS SCRIPT CAREFULLY BEFORE EXECUTION!\n\n";
        $script .= "set -e\n\n";
        
        $script .= "PROJECT_ROOT=\"" . $this->projectDir . "\"\n";
        $script .= "SOURCE_CODE_BASE=\"" . $sourceBaseDir . "\"\n"; // Use the new sourceBaseDir
        $script .= "DEPLOYMENT_DIR=\"{$deploymentDir}\"\n";
        $script .= "BACKUP_DIR=\"\$DEPLOYMENT_DIR/backup\"\n\n";
        
        $script .= "echo \"========================================\"\n";
        $script .= "echo \"   DEPLOYMENT PROCESS STARTED\"\n";
        $script .= "echo \"========================================\"\n";
        $script .= "echo \"\"\n\n";

        // 1. Backup
        if ($createBackup) {
            $script .= "echo \"[1/6] Creating backup...\"\n";
            $script .= "bash \"\$DEPLOYMENT_DIR/01_backup.sh\" \"\$BACKUP_DIR\"\n";
            $script .= "echo \"✓ Backup created\"\n";
            $script .= "echo \"\"\n\n";
        }

        // 2. Deploy files
        $script .= "echo \"[2/6] Deploying files...\"\n";
        foreach ($manifest['files'] as $fileInfo) {
            $sourceFile = $fileInfo['source_file'];
            $targetPath = $fileInfo['target_path'];
            $fullTarget = "\$PROJECT_ROOT/" . ltrim($targetPath, '/');
            
            $script .= "echo \"  Deploying: {$targetPath}\"\n";
            $script .= "mkdir -p \"$(dirname {$fullTarget})\"\n";
            $script .= "cp \"\$SOURCE_CODE_BASE{$sourceFile}\" \"{$fullTarget}\"\n"; // Use SOURCE_CODE_BASE
        }
        $script .= "echo \"✓ Files deployed\"\n";
        $script .= "echo \"\"\n\n";

        // 3. Composer update
        if ($manifest['analysis']['requires_composer_update']) {
            $script .= "echo \"[3/6] Updating dependencies...\"\n";
            $script .= "cd \"\$PROJECT_ROOT\"\n";
            $script .= "composer install --no-dev --optimize-autoloader\n";
            $script .= "echo \"✓ Dependencies updated\"\n";
            $script .= "echo \"\"\n\n";
        } else {
            $script .= "echo \"[3/6] Skipping composer update (not required)\"\n";
            $script .= "echo \"\"\n\n";
        }

        // 4. Cache clear
        if ($manifest['analysis']['requires_cache_clear']) {
            $script .= "echo \"[4/6] Clearing cache...\"\n";
            $script .= "cd \"\$PROJECT_ROOT\"\n";
            $script .= "php bin/console cache:clear --no-warmup\n";
            $script .= "php bin/console cache:warmup\n";
            $script .= "echo \"✓ Cache cleared\"\n";
            $script .= "echo \"\"\n\n";
        } else {
            $script .= "echo \"[4/6] Skipping cache clear (not required)\"\n";
            $script .= "echo \"\"\n\n";
        }

        // 5. Migrations
        if ($manifest['analysis']['requires_migration']) {
            $script .= "echo \"[5/6] Running migrations...\"\n";
            $script .= "cd \"\$PROJECT_ROOT\"\n";
            $script .= "php bin/console doctrine:migrations:migrate --no-interaction\n";
            $script .= "echo \"✓ Migrations executed\"\n";
            $script .= "echo \"\"\n\n";
        } else {
            $script .= "echo \"[5/6] Skipping migrations (not required)\"\n";
            $script .= "echo \"\"\n\n";
        }

        // 6. Tests
        if ($runTests) {
            $script .= "echo \"[6/6] Running tests...\"\n";
            $script .= "cd \"\$PROJECT_ROOT\"\n";
            $script .= "php bin/phpunit\n";
            $script .= "if [ \$? -eq 0 ]; then\n";
            $script .= "    echo \"✓ All tests passed\"\n";
            $script .= "else\n";
            $script .= "    echo \"❌ Tests failed! Consider rollback.\"\n";
            $script .= "    echo \"Run: bash \$DEPLOYMENT_DIR/03_rollback.sh\"\n";
            $script .= "    exit 1\n";
            $script .= "fi\n";
            $script .= "echo \"\"\n\n";
        } else {
            $script .= "echo \"[6/6] Skipping tests (disabled)\"\n";
            $script .= "echo \"\"\n\n";
        }

        $script .= "echo \"========================================\"\n";
        $script .= "echo \"   ✅ DEPLOYMENT COMPLETED\"\n";
        $script .= "echo \"========================================\"\n";

        $scriptPath = $deploymentDir . '/02_deploy.sh';
        $this->filesystem->dumpFile($scriptPath, $script);
        $this->filesystem->chmod($scriptPath, 0755);
        
        return $scriptPath;
    }

    /**
     * Erstellt Rollback-Skript
     */
    private function createRollbackScript(string $deploymentDir, array $manifest): void
    {
        $script = "#!/bin/bash\n\n";
        $script .= "# Rollback Script - Created " . date('Y-m-d H:i:s') . "\n";
        $script .= "# This script restores files from backup\n\n";
        $script .= "set -e\n\n";
        
        $script .= "PROJECT_ROOT=\"" . $this->projectDir . "\"\n";
        $script .= "BACKUP_DIR=\"{$deploymentDir}/backup\"\n\n";
        
        $script .= "if [ ! -d \"\$BACKUP_DIR\" ]; then\n";
        $script .= "    echo \"ERROR: Backup directory not found!\"\n";
        $script .= "    exit 1\n";
        $script .= "fi\n\n";
        
        $script .= "echo \"Starting rollback...\"\n\n";

        foreach ($manifest['files'] as $fileInfo) {
            $targetPath = $fileInfo['target_path'];
            $backupPath = "\$BACKUP_DIR/" . $targetPath;
            $fullPath = "\$PROJECT_ROOT/" . ltrim($targetPath, '/');
            
            $script .= "if [ -f \"{$backupPath}\" ]; then\n";
            $script .= "    cp \"{$backupPath}\" \"{$fullPath}\"\n";
            $script .= "    echo \"Restored: {$targetPath}\"\n";
            $script .= "fi\n\n";
        }

        $script .= "echo \"Clearing cache...\"\n";
        $script .= "cd \"\$PROJECT_ROOT\"\n";
        $script .= "php bin/console cache:clear\n\n";
        
        $script .= "echo \"✅ Rollback completed successfully\"\n";

        $scriptPath = $deploymentDir . '/03_rollback.sh';
        $this->filesystem->dumpFile($scriptPath, $script);
        $this->filesystem->chmod($scriptPath, 0755);
    }

    /**
     * Erstellt Deployment-README
     */
    private function createDeploymentReadme(string $deploymentDir, array $manifest, string $timestamp): void
    {
        $readme = "# DEPLOYMENT PACKAGE - {$timestamp}\n\n";
        $readme .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $readme .= "## ⚠️ IMPORTANT - READ BEFORE DEPLOYMENT\n\n";
        $readme .= "This deployment package contains code and configuration changes.\n";
        $readme .= "ALWAYS review all changes before executing deployment scripts.\n\n";
        
        $readme .= "## 📋 Deployment Summary\n\n";
        $readme .= "### Files to Deploy\n";
        foreach ($manifest['files'] as $fileInfo) {
            $readme .= "- `{$fileInfo['target_path']}` (Type: {$fileInfo['type']})\n";
        }
        $readme .= "\n";
        
        $analysis = $manifest['analysis'];
        $readme .= "### Required Actions\n";
        $readme .= "- Backup: " . ($manifest['analysis']['backup_created'] ?? false ? "✅ Yes" : "❌ No") . "\n"; // Check for backup_created flag
        $readme .= "- Composer Update: " . ($analysis['requires_composer_update'] ? "✅ Yes" : "❌ No") . "\n";
        $readme .= "- Cache Clear: " . ($analysis['requires_cache_clear'] ? "✅ Yes" : "❌ No") . "\n";
        $readme .= "- Database Migration: " . ($analysis['requires_migration'] ? "✅ Yes" : "❌ No") . "\n\n";
        
        $readme .= "## 🚀 Deployment Steps\n\n";
        $readme .= "### 1. Review Changes\n";
        $readme .= "```bash\n";
        $readme .= "# Review all files in:\n";
        $readme .= "cat manifest.json\n";
        $readme .= "```\n\n";
        
        $readme .= "### 2. Execute Deployment\n";
        $readme .= "```bash\n";
        $readme .= "bash 02_deploy.sh\n";
        $readme .= "```\n\n";
        
        $readme .= "### 3. Verify Deployment\n";
        $readme .= "- Check application logs\n";
        $readme .= "- Run manual tests\n";
        $readme .= "- Monitor error rates\n\n";
        
        $readme .= "## 🔄 Rollback\n\n";
        $readme .= "If something goes wrong:\n";
        $readme .= "```bash\n";
        $readme .= "bash {$deploymentDir}/03_rollback.sh\n";
        $readme .= "```\n\n";
        
        $readme .= "## 📊 File Checksums\n\n";
        $readme .= "```\n";
        foreach ($manifest['checksums'] as $file => $checksum) {
            $readme .= "{$file}: {$checksum}\n";
        }
        $readme .= "```\n";

        $this->filesystem->dumpFile($deploymentDir . '/README.md', $readme);
    }

    /**
     * Formatiert Validierungsfehler
     */
    private function formatValidationErrors(array $errors): string
    {
        $message = "❌ DEPLOYMENT VALIDATION FAILED\n\n";
        $message .= "The following errors must be fixed:\n\n";
        
        foreach ($errors as $error) {
            $message .= "  • {$error}\n";
        }
        
        return $message;
    }

    /**
     * Formatiert Erfolgsantwort
     */
    private function formatSuccessResponse(string $deploymentDir, array $manifest, string $timestamp): string
    {
        $relativePath = str_replace($this->projectDir, '', $deploymentDir);
        
        $response = "✅ DEPLOYMENT PACKAGE CREATED SUCCESSFULLY\n\n";
        $response .= "Package ID: {$timestamp}\n";
        $response .= "Location: {$relativePath}\n\n";
        
        $response .= "📦 Package Contents:\n";
        $response .= "  • " . count($manifest['files']) . " files to deploy\n";
        
        if (!empty($manifest['analysis']['config_files'])) {
            $response .= "  • " . count($manifest['analysis']['config_files']) . " config changes\n";
        }
        if (!empty($manifest['analysis']['migration_files'])) {
            $response .= "  • " . count($manifest['analysis']['migration_files']) . " migrations\n";
        }
        if (!empty($manifest['analysis']['test_files'])) {
            $response .= "  • " . count($manifest['analysis']['test_files']) . " test files\n";
        }
        if (!empty($manifest['analysis']['env_files'])) { // Include .env files in summary
            $response .= "  • " . count($manifest['analysis']['env_files']) . " .env files\n";
        }
        
        $response .= "\n";
        $response .= "⚠️  NEXT STEPS:\n\n";
        $response .= "1. Review the deployment package:\n";
        $response .= "   cd {$relativePath}\n";
        $response .= "   cat README.md\n\n";
        
        $response .= "2. Review changes in manifest.json\n\n";
        
        $response .= "3. Execute deployment:\n";
        $response .= "   bash {$relativePath}/02_deploy.sh\n\n";
        
        $response .= "4. If needed, rollback:\n";
        $response .= "   bash {$relativePath}/03_rollback.sh\n\n";
        
        $response .= "⚠️  IMPORTANT: Always backup your database separately before deployment!\n";
        
        return $response;
    }
}
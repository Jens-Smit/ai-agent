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
    description: 'Executes a PHP file from the generated_code directory in a secure Docker sandbox. It copies the necessary project parts (composer.json, composer.lock, and the specified generated PHP file) into a temporary directory, installs dependencies, and runs the PHP file.'
)]
final class SandboxExecutorTool
{
    private string $projectDir;
    private Filesystem $filesystem;
    private LoggerInterface $logger;
    private const GENERATED_CODE_DIR = '/generated_code/';
    private const DOCKERFILE_SANDBOX_NAME = 'Dockerfile.sandbox';
    private const DOCKER_IMAGE_NAME = 'ai-sandbox:latest';

    public function __construct(KernelInterface $kernel, LoggerInterface $logger)
    {
        $this->projectDir = $kernel->getProjectDir();
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
    }

    /**
     * Executes a PHP file in a secure Docker sandbox.
     *
     * @param string $filename The name of the PHP file to execute, located in the 'generated_code' directory (e.g., "MyTestScript.php").
     * @return string A message indicating the result of the execution.
     */
    public function __invoke(
        #[With(pattern: '/^[^\\\\/]+\\.php$/i')]
        string $filename
    ): string {
        $fullGeneratedCodePath = $this->projectDir . self::GENERATED_CODE_DIR;
        $filePathToExecute = $fullGeneratedCodePath . basename($filename); // basename for security

        if (!$this->filesystem->exists($filePathToExecute)) {
            $this->logger->error(sprintf('File not found in generated_code directory: %s', $filePathToExecute));
            return sprintf('ERROR: The file "%s" was not found in the generated_code directory.', $filename);
        }

        // 1. Create a unique temporary directory for the sandbox context
        // This tempDir is now for the Dockerfile.sandbox and temporary build context, not for copying the whole project.
        $tempBuildDir = $this->filesystem->tempnam(sys_get_temp_dir(), 'ai_sandbox_build_');
        $this->filesystem->remove($tempBuildDir); // Remove the file created by tempnam
        $this->filesystem->mkdir($tempBuildDir);
        $this->logger->info(sprintf('Created temporary Docker build directory: %s', $tempBuildDir));

        try {
            // 2. Copy Dockerfile.sandbox to temp build directory
            $dockerfileSource = $this->projectDir . '/' . self::DOCKERFILE_SANDBOX_NAME;
            $dockerfileDest = $tempBuildDir . '/' . self::DOCKERFILE_SANDBOX_NAME;
            if (!$this->filesystem->exists($dockerfileSource)) {
                throw new \RuntimeException(sprintf('Dockerfile.sandbox not found at %s', $dockerfileSource));
            }
            $this->filesystem->copy($dockerfileSource, $dockerfileDest);

            // Copy project's composer.json and composer.lock to tempBuildDir
            // These are needed by the Dockerfile during the build process
            $composerJsonSource = $this->projectDir . '/composer.json';
            $composerLockSource = $this->projectDir . '/composer.lock';

            if ($this->filesystem->exists($composerJsonSource)) {
                $this->filesystem->copy($composerJsonSource, $tempBuildDir . '/composer.json');
            } else {
                $this->logger->warning('composer.json not found in project root. Docker build might fail.');
            }
            if ($this->filesystem->exists($composerLockSource)) {
                $this->filesystem->copy($composerLockSource, $tempBuildDir . '/composer.lock');
            } else {
                 $this->logger->warning('composer.lock not found in project root. Docker build might not use locked dependencies.');
            }

            // 3. Build Docker image
            // The Dockerfile.sandbox should now include the composer install step.
            $this->logger->info(sprintf('Building Docker image %s from %s', self::DOCKER_IMAGE_NAME, $tempBuildDir));
            $buildProcess = Process::fromShellCommandline(sprintf('docker build -t %s -f %s .', self::DOCKER_IMAGE_NAME, self::DOCKERFILE_SANDBOX_NAME), $tempBuildDir);
            $buildProcess->setTimeout(300); // 5 minutes timeout for building
            $buildProcess->run();

            if (!$buildProcess->isSuccessful()) {
                $this->logger->error('Docker build failed', ['output' => $buildProcess->getOutput(), 'error' => $buildProcess->getErrorOutput()]);
                throw new \RuntimeException('Docker image build failed: ' . $buildProcess->getErrorOutput());
            }
            $this->logger->info('Docker image built successfully.');

            // 4. Mount the entire project directory as read-only and generated_code as writable
            // The 'generated_code' directory should be created if it doesn't exist for the mount to work consistently.
            if (!$this->filesystem->exists($fullGeneratedCodePath)) {
                $this->filesystem->mkdir($fullGeneratedCodePath, 0777, true);
            }

            $projectRootMount = sprintf('-v %s:/app:ro', escapeshellarg($this->projectDir));
            // The generated_code directory inside the container is already handled by the Dockerfile.
            // We just need to make sure the host's generated_code is mounted to /app/generated_code inside container.
            $generatedCodeMount = sprintf('-v %s:/app%s:rw', escapeshellarg($fullGeneratedCodePath), self::GENERATED_CODE_DIR);


            // 5. Execute the PHP file in the container
            $this->logger->info(sprintf('Executing PHP file "%s" in sandbox container...', $filename));
            // No need for a separate composer install step here, as it's part of the Dockerfile build.
            // The PHP script will be executed directly.
            $executeProcess = Process::fromShellCommandline(sprintf(
                'docker run --rm %s %s %s php /app%s%s',
                $projectRootMount,
                $generatedCodeMount,
                self::DOCKER_IMAGE_NAME,
                self::GENERATED_CODE_DIR,
                escapeshellarg(basename($filename))
            ));
            $executeProcess->setTimeout(120); // 2 minutes timeout for script execution
            $executeProcess->run();

            if (!$executeProcess->isSuccessful()) {
                $this->logger->error('PHP script execution failed in sandbox', ['output' => $executeProcess->getOutput(), 'error' => $executeProcess->getErrorOutput()]);
                return sprintf(
                    'ERROR: PHP file "%s" execution failed in sandbox. Output: %s. Error: %s',
                    $filename,
                    $executeProcess->getOutput(),
                    $executeProcess->getErrorOutput()
                );
            }
            $this->logger->info('PHP script executed successfully in sandbox.');

            return sprintf(
                'SUCCESS: PHP file "%s" executed successfully in sandbox. Output: %s',
                $filename,
                $executeProcess->getOutput()
            );

        } catch (\Exception $e) {
            $this->logger->error('Sandbox execution failed unexpectedly', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return sprintf('ERROR: Sandbox execution failed due to an unexpected error: %s', $e->getMessage());
        } finally {
            // 6. Clean up temporary build directory
            if ($this->filesystem->exists($tempBuildDir)) {
                $this->filesystem->remove($tempBuildDir);
                $this->logger->info(sprintf('Cleaned up temporary Docker build directory: %s', $tempBuildDir));
            }
        }
    }
}

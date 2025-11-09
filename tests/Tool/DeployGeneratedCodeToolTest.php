<?php

namespace App\\Tests\\Tool;

use App\\Tool\\DeployGeneratedCodeTool;
use PHPUnit\\Framework\\TestCase;
use Symfony\\Component\\HttpKernel\\KernelInterface;
use Symfony\\Component\\Filesystem\\Filesystem;
use Psr\\Log\\LoggerInterface;

class DeployGeneratedCodeToolTest extends TestCase
{
    private string $projectDir;
    private string $generatedCodeDir;
    private Filesystem $filesystem;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir() . '/ai_agent_test_project';
        $this->generatedCodeDir = $this->projectDir . '/generated_code';
        $this->filesystem = new Filesystem();
        $this->logger = $this->createMock(LoggerInterface::class);

        // Ensure a clean state for each test
        $this->filesystem->remove($this->projectDir);
        $this->filesystem->mkdir($this->projectDir);
        $this->filesystem->mkdir($this->generatedCodeDir);

        // Mock KernelInterface to return our test project directory
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $this->tool = new DeployGeneratedCodeTool($kernel, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
        parent::tearDown();
    }

    public function testGenerateScriptSuccessfully(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('Deployment script generated:'));

        // Create some dummy files in generated_code
        $this->filesystem->dumpFile($this->generatedCodeDir . '/test_file_1.php', '<?php echo "hello";');
        $this->filesystem->dumpFile($this->generatedCodeDir . '/config.yaml', 'key: value');

        $filesToDeploy = [
            ['source_file' => 'test_file_1.php', 'target_path' => 'src/Service/test_file_1.php'],
            ['source_file' => 'config.yaml', 'target_path' => 'config/packages/config.yaml'],
        ];

        $result = $this->tool->__invoke($filesToDeploy);

        $this->assertStringContainsString('SUCCESS: Deployment script', $result);
        $this->assertStringContainsString('generated in the `generated_code` directory.', $result);
        $this->assertStringContainsString('bash generated_code/', $result);

        // Verify script file exists and is executable
        preg_match('/deploy_generated_code_\\d{14}.sh/', $result, $matches);
        $scriptFilename = $matches[0] ?? null;
        $this->assertNotNull($scriptFilename);

        $scriptPath = $this->generatedCodeDir . '/' . $scriptFilename;
        $this->assertTrue($this->filesystem->exists($scriptPath));
        $this->assertTrue(is_executable($scriptPath));

        $scriptContent = file_get_contents($scriptPath);
        $this->assertIsString($scriptContent);
        $this->assertStringContainsString('cp \'.' . $this->generatedCodeDir . '/test_file_1.php\' \'.' . $this->projectDir . '/src/Service/test_file_1.php\'', $scriptContent);
        $this->assertStringContainsString('cp \'.' . $this->generatedCodeDir . '/config.yaml\' \'.' . $this->projectDir . '/config/packages/config.yaml\'', $scriptContent);
    }

    public function testNoFilesToDeploy(): void
    {
        $result = $this->tool->__invoke([]);
        $this->assertStringContainsString('WARNING: No files specified for deployment. Nothing to do.', $result);
    }

    public function testSourceFileDoesNotExist(): void
    {
        $filesToDeploy = [
            ['source_file' => 'non_existent_file.php', 'target_path' => 'src/Service/non_existent_file.php'],
        ];

        $result = $this->tool->__invoke($filesToDeploy);

        $this->assertStringContainsString('WARNING: The following files could not be prepared for deployment due to errors:', $result);
        $this->assertStringContainsString('Source file \"non_existent_file.php\" not found in `generated_code` directory.', $result);

        // Script should still be generated but might be empty or contain only boilerplate
        preg_match('/deploy_generated_code_\\d{14}.sh/', $result, $matches);
        $scriptFilename = $matches[0] ?? null;
        $this->assertNotNull($scriptFilename);
        $scriptPath = $this->generatedCodeDir . '/' . $scriptFilename;
        $this->assertTrue($this->filesystem->exists($scriptPath));
    }

    public function testInvalidTargetPathPreventsDeployment(): void
    {
        $this->filesystem->dumpFile($this->generatedCodeDir . '/valid_file.php', '<?php echo "valid";');

        $filesToDeploy = [
            ['source_file' => 'valid_file.php', 'target_path' => '/../outside_project.php'], // Path traversal attempt
        ];

        $result = $this->tool->__invoke($filesToDeploy);

        $this->assertStringContainsString('WARNING: The following files could not be prepared for deployment due to errors:', $result);
        $this->assertStringContainsString('Target path \"/../outside_project.php\" resolves outside project root. Skipping.', $result);
    }

    public function testGeneratedCodeDirDoesNotExist(): void
    {
        // Remove the generated_code directory before testing
        $this->filesystem->remove($this->generatedCodeDir);

        $filesToDeploy = [
            ['source_file' => 'test.php', 'target_path' => 'src/test.php'],
        ];

        $result = $this->tool->__invoke($filesToDeploy);

        $this->assertStringContainsString('ERROR: The `generated_code` directory does not exist. No files can be deployed.', $result);
    }

    public function testInvalidSourceFilenamePreventsDeployment(): void
    {
        $filesToDeploy = [
            ['source_file' => '../foo.php', 'target_path' => 'src/foo.php'], // Path traversal attempt in source
        ];

        $result = $this->tool->__invoke($filesToDeploy);
        $this->assertStringContainsString('Source file \"../foo.php\" contains directory traversal characters. Skipping.', $result);
    }
}

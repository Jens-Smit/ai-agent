<?php 
// src/MessageHandler/FrontendGeneratorJobHandler.php

namespace App\MessageHandler;

use App\Message\FrontendGeneratorJob;
use App\Service\AgentStatusService;
use App\Tool\DeployGeneratedCodeTool;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FrontendGeneratorJobHandler
{
    private const MAX_RETRIES = 25;
    private const RETRY_DELAY_SECONDS = 60;

    public function __construct(
        #[Autowire(service: 'ai.agent.frontend_generator')]
        private AgentInterface $agent,
        private AgentStatusService $agentStatusService,
        private DeployGeneratedCodeTool $deployTool,
        private LoggerInterface $logger
    ) {}

    public function __invoke(FrontendGeneratorJob $job): void
    {
        $this->logger->info('FrontendGeneratorJobHandler: Job empfangen.', [
            'prompt' => $job->prompt,
            'sessionId' => $job->sessionId
        ]);

        $this->agentStatusService->clearStatuses($job->sessionId);
        $this->agentStatusService->addStatus($job->sessionId, 'Frontend Generator Job gestartet');

        $messages = new MessageBag(Message::ofUser($job->prompt));
        
        $attempt = 1;
        $result = null;

        while ($attempt <= self::MAX_RETRIES) {
            try {
                $this->agentStatusService->addStatus(
                    $job->sessionId,
                    sprintf('AI-Agent Aufruf (Versuch %d/%d)', $attempt, self::MAX_RETRIES)
                );

                $result = $this->agent->call($messages);
                
                $this->agentStatusService->addStatus($job->sessionId, 'Antwort erhalten');
                break;

            } catch (\Throwable $e) {
                $this->handleError($e, $attempt, $job->sessionId);
                
                if ($this->isRetriable($e) && $attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY_SECONDS);
                    $attempt++;
                    continue;
                }
                
                return;
            }
        }

        if ($result !== null) {
            $this->processResult($result, $job->sessionId);
        }
    }

    private function isRetriable(\Throwable $e): bool
    {
        $errorMessage = $e->getMessage();
        return $e instanceof \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface ||
               $e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface ||
               str_contains($errorMessage, '503') ||
               str_contains($errorMessage, 'UNAVAILABLE');
    }

    private function handleError(\Throwable $e, int $attempt, string $sessionId): void
    {
        $this->logger->error('Frontend Generator Fehler', [
            'error' => $e->getMessage(),
            'attempt' => $attempt
        ]);
        
        $this->agentStatusService->addStatus(
            $sessionId,
            sprintf('Fehler (Versuch %d): %s', $attempt, $e->getMessage())
        );
    }

    private function processResult($result, string $sessionId): void
    {
        $content = method_exists($result, 'getContent') ? $result->getContent() : (string) $result;
        
        if (empty($content)) {
            $this->agentStatusService->addStatus($sessionId, 'ERROR: Kein Inhalt vom Agent');
            return;
        }

        // Check for generated files
        $generatedCodeDir = __DIR__ . '/../../generated_code/';
        $recentFiles = $this->getRecentFiles($generatedCodeDir);

        if (!empty($recentFiles)) {
            $this->agentStatusService->addStatus(
                $sessionId,
                sprintf('Dateien erstellt: %s', implode(', ', $recentFiles))
            );
            
            $deploymentResult = $this->createDeploymentPackage($recentFiles);
            
            $this->agentStatusService->addStatus($sessionId, 'Deployment-Paket erstellt');
            $this->agentStatusService->addStatus($sessionId, 'DEPLOYMENT:' . $deploymentResult);
        }

        $this->agentStatusService->addStatus($sessionId, 'RESULT:' . $content);
    }

    private function getRecentFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $recentFiles = [];
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filepath = $dir . $file;
            if (filemtime($filepath) > time() - 60) {
                $recentFiles[] = $file;
            }
        }
        
        return $recentFiles;
    }

    private function createDeploymentPackage(array $files): string
    {
        $filesToDeploy = [];
        
        foreach ($files as $file) {
            $targetPath = $this->determineTargetPath($file);
            $filesToDeploy[] = [
                'source_file' => $file,
                'target_path' => $targetPath
            ];
        }

        return $this->deployTool->__invoke($filesToDeploy);
    }

    private function determineTargetPath(string $file): string
    {
        if (str_ends_with($file, 'Test.php')) {
            return 'tests/' . $file;
        }
        
        if (str_ends_with($file, '.php')) {
            return 'src/' . $file;
        }
        
        if (str_ends_with($file, '.yaml') || str_ends_with($file, '.json')) {
            return 'config/' . $file;
        }
        
        if (preg_match('/^Version\d{14}\.php$/', $file)) {
            return 'migrations/' . $file;
        }
        
        return 'generated_code/' . $file;
    }
}
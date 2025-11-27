<?php
// src/Command/WorkflowSchedulerCommand.php

declare(strict_types=1);

namespace App\Command;

use App\Repository\WorkflowRepository;
use App\Service\WorkflowEngine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scheduler für zeitgesteuerte Workflows
 * 
 * Cronjob-Beispiel (jede Minute):
 * * * * * * php bin/console app:workflow:scheduler
 */
#[AsCommand(
    name: 'app:workflow:scheduler',
    description: 'Führt geplante Workflows aus'
)]
class WorkflowSchedulerCommand extends Command
{
    public function __construct(
        private WorkflowRepository $workflowRepo,
        private WorkflowEngine $workflowEngine,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Zeige nur Workflows ohne Ausführung')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max. Anzahl Workflows pro Durchlauf', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $io->title('Workflow Scheduler');

        // Hole fällige Workflows
        $dueWorkflows = $this->workflowRepo->getDueWorkflows($limit);

        if (empty($dueWorkflows)) {
            $io->success('Keine fälligen Workflows gefunden');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Gefundene Workflows: %d', count($dueWorkflows)));

        $executed = 0;
        $failed = 0;

        foreach ($dueWorkflows as $workflow) {
            $io->section(sprintf(
                'Workflow #%d: %s',
                $workflow->getId(),
                substr($workflow->getUserIntent(), 0, 60)
            ));

            $io->text([
                sprintf('Schedule: %s', $workflow->getScheduleType()),
                sprintf('Next Run: %s', $workflow->getNextRunAt()->format('Y-m-d H:i:s')),
                sprintf('Execution Count: %d', $workflow->getExecutionCount())
            ]);

            if ($dryRun) {
                $io->note('DRY RUN - würde ausgeführt werden');
                continue;
            }

            try {
                $this->workflowEngine->executeWorkflow($workflow, $workflow->getUser());
                
                $workflow->markExecuted();
                $this->em->flush();

                $io->success(sprintf(
                    'Workflow #%d erfolgreich ausgeführt (Total: %d)',
                    $workflow->getId(),
                    $workflow->getExecutionCount()
                ));

                if ($workflow->getNextRunAt()) {
                    $io->text(sprintf('Nächster Lauf: %s', $workflow->getNextRunAt()->format('Y-m-d H:i:s')));
                }

                $executed++;

            } catch (\Exception $e) {
                $this->logger->error('Scheduled workflow execution failed', [
                    'workflow_id' => $workflow->getId(),
                    'error' => $e->getMessage()
                ]);

                $io->error(sprintf('Fehler: %s', $e->getMessage()));
                $failed++;
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Erfolgreich' => $executed],
            ['Fehlgeschlagen' => $failed],
            ['Gesamt' => count($dueWorkflows)]
        );

        return Command::SUCCESS;
    }
}
<?php
// src/Command/TokenUsageCleanupCommand.php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TokenUsageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:token-usage:cleanup',
    description: 'Bereinigt alte Token-Usage-Einträge aus der Datenbank'
)]
class TokenUsageCleanupCommand extends Command
{
    public function __construct(
        private TokenUsageRepository $tokenUsageRepo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'days',
                InputArgument::OPTIONAL,
                'Anzahl der Tage, die behalten werden sollen',
                90
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getArgument('days');

        $io->title('Token Usage Cleanup');
        $io->text(sprintf('Bereinige Einträge älter als %d Tage...', $days));

        try {
            $deletedCount = $this->tokenUsageRepo->cleanupOldEntries($days);

            $io->success(sprintf(
                '%d alte Token-Usage-Einträge wurden erfolgreich gelöscht.',
                $deletedCount
            ));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Fehler beim Bereinigen: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
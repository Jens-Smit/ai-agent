<?php
// src/Command/TokenUsageStatsCommand.php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\TokenTrackingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:token-usage:stats',
    description: 'Zeigt Token-Usage-Statistiken für einen User an'
)]
class TokenUsageStatsCommand extends Command
{
    public function __construct(
        private TokenTrackingService $tokenService,
        private UserRepository $userRepo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'user-id',
                InputArgument::REQUIRED,
                'User ID'
            )
            ->addOption(
                'period',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Zeitraum (minute, hour, day, week, month)',
                'day'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getArgument('user-id');
        $period = $input->getOption('period');

        $user = $this->userRepo->find($userId);
        
        if (!$user) {
            $io->error(sprintf('User mit ID %d nicht gefunden.', $userId));
            return Command::FAILURE;
        }

        $io->title(sprintf('Token Usage Statistiken für User: %s (%d)', $user->getEmail(), $userId));

        try {
            $stats = $this->tokenService->getUsageStatistics($user, $period);

            // Übersicht
            $io->section('Übersicht');
            $io->table(
                ['Metrik', 'Wert'],
                [
                    ['Zeitraum', $stats['period'] ?? 'Custom'],
                    ['Start', $stats['start_date']],
                    ['Ende', $stats['end_date']],
                    ['Gesamt Tokens', number_format($stats['total_tokens'])],
                    ['Input Tokens', number_format($stats['total_input_tokens'])],
                    ['Output Tokens', number_format($stats['total_output_tokens'])],
                    ['Kosten (Cents)', number_format($stats['total_cost_cents'])],
                    ['Kosten (USD)', '$' . $stats['total_cost_dollars']],
                ]
            );

            // Breakdown nach Model
            if (!empty($stats['by_model'])) {
                $io->section('Nutzung nach Model');
                $table = new Table($output);
                $table->setHeaders([
                    'Model',
                    'Agent Type',
                    'Input',
                    'Output',
                    'Total',
                    'Requests',
                    'Avg Time (ms)',
                    'Cost (¢)'
                ]);

                foreach ($stats['by_model'] as $model) {
                    $table->addRow([
                        $model['model'],
                        $model['agentType'],
                        number_format($model['input_tokens']),
                        number_format($model['output_tokens']),
                        number_format($model['total_tokens']),
                        $model['request_count'],
                        number_format($model['avg_response_time'] ?? 0),
                        number_format($model['cost_cents']),
                    ]);
                }

                $table->render();
            }

            // Limits
            if (!empty($stats['limits'])) {
                $io->section('Aktuelle Limits');
                $table = new Table($output);
                $table->setHeaders(['Zeitraum', 'Limit', 'Genutzt', 'Verfügbar', 'Prozent']);

                foreach ($stats['limits'] as $period => $data) {
                    $table->addRow([
                        ucfirst($period),
                        number_format($data['limit']),
                        number_format($data['used']),
                        number_format($data['remaining']),
                        $data['percent'] . '%'
                    ]);
                }

                $table->render();
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Fehler beim Abrufen der Statistiken: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronExecutions;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shows what the cron scheduler actually DID — the recent CronExecutions, newest first, across all Crons or for one
 * Cron (by name or id), optionally only the FAILED (or only the SUCCESSFUL) ones. Each row carries start, duration,
 * state and the first line of the captured output; `--output` prints every execution's full output underneath, which
 * is where a failing command's real error text lives. Read-only; the counterpart to `app:crons:list` (what is
 * registered) and `app:crons:execute` (run what is due).
 */
#[AsCommand(
    name: 'app:crons:executions',
    description: 'Show recent Cron executions (all Crons or --cron=<name|id>), optionally --state=FAILED|SUCCESSFUL and with --output',
)]
class CronsExecutions extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('cron', null, InputOption::VALUE_OPTIONAL, 'Restrict to one Cron — its unique name or numeric id')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'How many executions to show (newest first)', '20')
            ->addOption('state', null, InputOption::VALUE_OPTIONAL, 'Only executions with this execution state: ' . CronExecution::EXECUTION_STATE_SUCCESSFUL . ' | ' . CronExecution::EXECUTION_STATE_FAILED)
            ->addOption('output', null, InputOption::VALUE_NONE, 'Print each execution\'s FULL captured output (default: first line only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(120);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAdminAccount) {
            AuthService::instance()->setAccount($defaultAdminAccount);
        }
        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');

        $limit = max(1, (int)$input->getOption('limit'));
        $state = $input->getOption('state') !== null ? strtoupper(trim((string)$input->getOption('state'))) : null;
        if ($state !== null && !in_array($state, [CronExecution::EXECUTION_STATE_SUCCESSFUL, CronExecution::EXECUTION_STATE_FAILED], true)) {
            $symfonyStyle->error('--state must be ' . CronExecution::EXECUTION_STATE_SUCCESSFUL . ' or ' . CronExecution::EXECUTION_STATE_FAILED . '.');
            return Command::FAILURE;
        }

        try {
            $cron = null;
            $cronOption = $input->getOption('cron');
            if ($cronOption !== null && trim((string)$cronOption) !== '') {
                $cronsService = Crons::getService();
                $cronsService->throwErrors = false;
                $cron = ctype_digit(trim((string)$cronOption))
                    ? $cronsService->find((int)$cronOption)
                    : $cronsService->findByName(trim((string)$cronOption));
                if (!$cron instanceof Cron) {
                    $symfonyStyle->error("No Cron '$cronOption' (by name or id). `app:crons:list` shows the registered ones.");
                    return Command::FAILURE;
                }
                $this->renderCronHeader($symfonyStyle, $cron);
            }

            $cronExecutions = CronExecutions::getService()->findRecentExecutions($cron, $limit, $state);
            // Cron names by id, each distinct Cron loaded ONCE — not through every execution's lazy parent relation
            // (which throws when accessed on rows loaded through the set repo) and not through CronsService::list()
            // (capped at 10 by the set's QueryOptions, so ids beyond the first page would stay unnamed).
            $cronNamesById = $cron ? [(int)$cron->id => $cron->name] : [];
            $cronsServiceForNames = Crons::getService();
            $cronsServiceForNames->throwErrors = false;
            foreach ($cronExecutions->getElements() as $cronExecution) {
                $cronId = (int)$cronExecution->cronId;
                if (!array_key_exists($cronId, $cronNamesById)) {
                    $knownCron = $cronsServiceForNames->find($cronId);
                    $cronNamesById[$cronId] = $knownCron instanceof Cron ? $knownCron->name : "#$cronId (deleted)";
                }
            }
            $cronNameOf = static fn (CronExecution $cronExecution): string => $cronNamesById[(int)$cronExecution->cronId] ?? ('#' . $cronExecution->cronId);
            if ($cronExecutions->count() === 0) {
                $symfonyStyle->note('No executions match.' . ($cron ? ' The Cron has not run yet on this database — or its executions were cleaned up (14 days).' : ''));
                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($cronExecutions->getElements() as $cronExecution) {
                $rows[] = [
                    $cronExecution->id,
                    $cronNameOf($cronExecution),
                    isset($cronExecution->executionStartedAt) ? $cronExecution->executionStartedAt->format('Y-m-d H:i:s') : '-',
                    $this->formatDuration($cronExecution),
                    $cronExecution->state ?? '',
                    $cronExecution->executionState ?? ($cronExecution->state === CronExecution::STATE_RUNNING ? '…' : ''),
                    $input->getOption('output') ? '(below)' : $this->firstLine($cronExecution->output ?? ''),
                ];
            }
            $symfonyStyle->table(['Id', 'Cron', 'Started', 'Duration', 'State', 'Result', 'Output'], $rows);

            if ($input->getOption('output')) {
                foreach ($cronExecutions->getElements() as $cronExecution) {
                    $symfonyStyle->section(
                        "#{$cronExecution->id} · " . $cronNameOf($cronExecution)
                        . ' · ' . (isset($cronExecution->executionStartedAt) ? $cronExecution->executionStartedAt->format('Y-m-d H:i:s') : '-')
                        . ' · ' . ($cronExecution->executionState ?? $cronExecution->state ?? '')
                    );
                    $symfonyStyle->writeln(trim((string)($cronExecution->output ?? '')) === '' ? '(no output captured)' : rtrim((string)$cronExecution->output));
                }
            }
            return Command::SUCCESS;
        } catch (Throwable $t) {
            $symfonyStyle->error(get_class($t) . ': ' . $t->getMessage());
            return Command::FAILURE;
        }
    }

    protected function renderCronHeader(SymfonyStyle $symfonyStyle, Cron $cron): void
    {
        $symfonyStyle->title("Cron '{$cron->name}' (id {$cron->id})");
        $symfonyStyle->table(
            ['Field', 'Value'],
            [
                ['description', $cron->description ?? ''],
                ['schedule', $cron->schedule ?? ''],
                ['command', $cron->command ?? ''],
                ['active', $cron->isActive ? 'yes' : 'no'],
                ['last execution started', isset($cron->lastExecutionStartedAt) ? $cron->lastExecutionStartedAt->format('Y-m-d H:i:s') : '-'],
                ['next execution scheduled', isset($cron->nextExecutionScheduledAt) ? $cron->nextExecutionScheduledAt->format('Y-m-d H:i:s') : '-'],
            ]
        );
    }

    protected function formatDuration(CronExecution $cronExecution): string
    {
        if (!isset($cronExecution->executionStartedAt)) {
            return '-';
        }
        if (!isset($cronExecution->executionEndedAt)) {
            return $cronExecution->state === CronExecution::STATE_RUNNING ? 'running' : '-';
        }
        $seconds = $cronExecution->executionEndedAt->getTimestamp() - $cronExecution->executionStartedAt->getTimestamp();
        return $seconds < 60 ? "{$seconds}s" : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    protected function firstLine(string $text): string
    {
        $firstLine = trim((string)strtok(trim($text), "\n"));
        if ($firstLine === '') {
            return '';
        }
        return mb_strlen($firstLine) > 80 ? mb_substr($firstLine, 0, 77) . '…' : $firstLine;
    }
}

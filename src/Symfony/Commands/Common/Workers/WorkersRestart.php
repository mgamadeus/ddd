<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:restart',
    description: 'Asks the live worker processes (of one transport, or all) to stop after their current message; whatever runs the workers starts them again. Needs the worker monitoring'
)]
class WorkersRestart extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::OPTIONAL, 'Only the worker processes of this transport')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Show the processes that would be asked, ask none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $workersSettings = WorkersSettings::fromEnvironment();
        if (!$workersSettings->enabled) {
            $symfonyStyle->getErrorStyle()->error('The worker monitoring is switched off (Common.Workers enabled / WORKERS_ENABLED): the processes are not known and cannot be asked.');
            return Command::FAILURE;
        }
        $transport = $input->getArgument('transport');
        $transport = is_string($transport) && $transport !== '' ? $transport : null;
        $now = DateTime::fromTimestamp(time());
        $workerProcessesService = WorkerProcesses::getService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $workerProcesses = $input->getOption('dryRun')
                ? $workerProcessesService->findLiveWorkerProcesses($now, $workersSettings->getLostAfterSeconds(), $workersSettings->busySilentSeconds, $transport)
                : $workerProcessesService->requestRestartOfWorkerProcesses($now, $workersSettings->getLostAfterSeconds(), $workersSettings->busySilentSeconds, $transport);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $symfonyStyle->table(
            ['Process', 'Transport', 'Started', 'Current message'],
            array_map(
                static fn(WorkerProcess $workerProcess): array => [
                    $workerProcess->processKey,
                    $workerProcess->transport,
                    (string)($workerProcess->startedAt ?? ''),
                    $workerProcess->currentMessageClass ?? '',
                ],
                $workerProcesses->getElements()
            )
        );
        $symfonyStyle->text(sprintf(
            $input->getOption('dryRun') ? '%d process(es) would be asked to restart.' : '%d process(es) asked to restart: each stops after its current message, at the latest at its next heartbeat.',
            $workerProcesses->count()
        ));
        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:processes',
    description: 'Lists worker processes — live, stopped and lost —, newest first: display state, start, heartbeat, counts, current message, stop and stop reason; --live shows only the ones alive now'
)]
class WorkersProcesses extends WorkersReport
{
    public const array STORED_STATES = [WorkerProcess::STATE_RUNNING, WorkerProcess::STATE_STOPPING, WorkerProcess::STATE_STOPPED];

    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::OPTIONAL, 'Only the processes that consume this transport')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Only this stored state: ' . implode(', ', self::STORED_STATES))
            ->addOption('stopReason', null, InputOption::VALUE_REQUIRED, 'Only this stop reason, e.g. LOST, MEMORY_LIMIT, RESTART_REQUESTED, SIGNAL')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only processes started within this many minutes')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most processes to list', (string)self::DEFAULT_LIMIT)
            ->addOption('live', null, InputOption::VALUE_NONE, 'Only the processes alive now (running, stopping or busy); the other filters are not applied')
            ->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $workersSettings = WorkersSettings::fromEnvironment();
        $now = DateTime::fromTimestamp(time());
        $transport = $this->getStringArgument($input, 'transport');
        $limit = $this->getLimit($input);
        try {
            /** @var WorkerProcesses $workerProcesses */
            $workerProcesses = $this->readWithoutRightsRestrictions(
                fn() => $input->getOption('live')
                    ? $this->findLiveWorkerProcesses($now, $workersSettings, $transport, $limit)
                    : $this->findWorkerProcesses($input, $now, $transport, $limit)
            );
        } catch (BadRequestException $exception) {
            $symfonyStyle->getErrorStyle()->error($exception->getMessage());
            return Command::FAILURE;
        }
        WorkerProcesses::getService()->setDisplayStates($workerProcesses, $now, $workersSettings->getLostAfterSeconds());
        if ($this->isJsonFormat($input)) {
            $this->writeJsonPayload($output, 'workerProcesses', $workerProcesses);
            return Command::SUCCESS;
        }
        $this->noteWhenMonitoringIsOff($symfonyStyle);
        $this->renderWorkerProcessesTable($symfonyStyle, $workerProcesses);
        $symfonyStyle->text(sprintf('%d process(es)', $workerProcesses->count()));
        return Command::SUCCESS;
    }

    protected function findLiveWorkerProcesses(DateTime $now, WorkersSettings $workersSettings, ?string $transport, int $limit): WorkerProcesses
    {
        $liveWorkerProcesses = WorkerProcesses::getService()->findLiveWorkerProcesses(
            $now,
            $workersSettings->getLostAfterSeconds(),
            $workersSettings->busySilentSeconds,
            $transport
        );
        $workerProcesses = new WorkerProcesses();
        foreach (array_slice(array_reverse($liveWorkerProcesses->getElements()), 0, $limit) as $workerProcess) {
            $workerProcesses->add($workerProcess);
        }
        return $workerProcesses;
    }

    /**
     * @throws BadRequestException When a filter value is not valid
     */
    protected function findWorkerProcesses(InputInterface $input, DateTime $now, ?string $transport, int $limit): WorkerProcesses
    {
        $conditions = [];
        if (($state = $this->getStringOption($input, 'state')) !== null) {
            $conditions[] = 'state eq ' . $this->quoteFilterValue(strtoupper($state));
        }
        if (($stopReason = $this->getStringOption($input, 'stopReason')) !== null) {
            $conditions[] = 'stopReason eq ' . $this->quoteFilterValue(strtoupper($stopReason));
        }
        if ($this->getStringOption($input, 'since') !== null) {
            $conditions[] = 'startedAt ge ' . $this->quoteFilterValue(gmdate('Y-m-d H:i:s', $now->getTimestamp() - $this->getSinceMinutes($input) * 60));
        }
        WorkerProcesses::setDefaultQueryOptionsSnapshot();
        try {
            $queryOptions = WorkerProcesses::getDefaultQueryOptions();
            $filters = $conditions !== [] ? FiltersOptions::fromString(implode(' and ', $conditions)) : null;
            $queryOptions->setFilters($filters);
            $newestFirst = OrderByOptions::fromString('startedAt desc');
            $queryOptions->setOrderBy($newestFirst);
            $queryOptions->setTop($limit);
            $workerProcessesService = WorkerProcesses::getService();
            return $transport !== null
                ? $workerProcessesService->findWorkerProcessesOfTransport($transport)
                : ($workerProcessesService->findAll() ?? new WorkerProcesses());
        } finally {
            WorkerProcesses::restoreDefaultQueryOptionsSnapshot();
        }
    }
}

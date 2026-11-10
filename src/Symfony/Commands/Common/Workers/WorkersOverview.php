<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkersOverview as WorkersOverviewPayload;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverview;
use DDD\Domain\Common\Entities\Workers\WorkerTransportOverviews;
use DDD\Domain\Common\Entities\Workers\WorkerTransportThroughputs;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:overview',
    description: 'Shows every transport now: state, waiting messages, messages in work, live processes, what was handled and failed in the last minutes, the last error; plus totals and live processes'
)]
class WorkersOverview extends WorkersReport
{
    protected function configure(): void
    {
        $this
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport')
            ->addOption('onlyActive', null, InputOption::VALUE_NONE, 'Only transports that are not idle or did something in the last minutes')
            ->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var WorkersOverviewService $workersOverviewService */
        $workersOverviewService = DDDService::instance()->getService(WorkersOverviewService::class);
        /** @var WorkersOverviewPayload $workersOverview */
        $workersOverview = $this->readWithoutRightsRestrictions(static fn() => $workersOverviewService->getWorkersOverview());
        $transport = $this->getStringOption($input, 'transport');
        if ($transport !== null || $input->getOption('onlyActive')) {
            $this->restrictToTransports($workersOverview, $transport, (bool)$input->getOption('onlyActive'));
        }
        if ($this->isJsonFormat($input)) {
            $this->writeJsonPayload($output, 'workersOverview', $workersOverview);
            return Command::SUCCESS;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $this->noteWhenMonitoringIsOff($symfonyStyle);
        $recentMinutes = intdiv($workersOverview->recentWindowSeconds, 60);
        $symfonyStyle->text(sprintf(
            'Generated %s UTC · monitoring %s · sampler %s · waiting %s · in work %d · live processes %d · last hour: %d handled, %d failed',
            $this->formatDateTime($workersOverview->generatedAt),
            $workersOverview->monitoringEnabled ? 'on' : 'off',
            $workersOverview->samplerActive ? 'active' : 'not running',
            $workersOverview->totals->waiting ?? 'unknown',
            $workersOverview->totals->inFlight,
            $workersOverview->totals->liveProcessCount,
            $workersOverview->totals->handledCountLastHour,
            $workersOverview->totals->failedCountLastHour
        ));
        $symfonyStyle->table(
            [
                'Transport', 'State', 'Waiting', 'In work', 'Processes', 'Defined',
                "Handled {$recentMinutes}m", "Thrown {$recentMinutes}m", "Caught {$recentMinutes}m", 'Avg ms', 'Avg wait ms', 'Last error',
            ],
            array_map(
                fn(WorkerTransportOverview $workerTransportOverview): array => [
                    $workerTransportOverview->transport,
                    $workerTransportOverview->state . ($workerTransportOverview->restartPending ? ' (restart pending)' : ''),
                    $workerTransportOverview->waiting ?? $workerTransportOverview->waitingState,
                    $workerTransportOverview->inFlight,
                    $workerTransportOverview->liveProcessCount,
                    $workerTransportOverview->isDefined
                        ? $workerTransportOverview->effectiveMin . '–' . $workerTransportOverview->effectiveMax
                        : 'no',
                    $workerTransportOverview->handledCount,
                    $workerTransportOverview->thrownCount,
                    $workerTransportOverview->caughtCount,
                    $workerTransportOverview->durationAverageMs ?? '',
                    $workerTransportOverview->waitAverageMs ?? '',
                    $workerTransportOverview->lastErrorClass !== null
                        ? $this->formatDateTime($workerTransportOverview->lastErrorAt) . ' ' . $workerTransportOverview->lastErrorClass
                        : '',
                ],
                $workersOverview->transports->getElements()
            )
        );
        $workerTransportThroughputs = new WorkerTransportThroughputs();
        foreach ($workersOverview->transports->getElements() as $workerTransportOverview) {
            $workerTransportThroughputs->getOrCreateByTransport($workerTransportOverview->transport)->throughputWindows = $workerTransportOverview->throughputWindows;
        }
        $workerTransportThroughputs->getOrCreateByTransport(self::ALL_TRANSPORTS_LABEL)->throughputWindows = $workersOverview->totals->throughputWindows;
        $this->renderThroughputTable($symfonyStyle, $workerTransportThroughputs);
        if (!$workersOverview->processes->isEmpty()) {
            $this->renderWorkerProcessesTable($symfonyStyle, $workersOverview->processes);
        }
        return Command::SUCCESS;
    }

    /** Keeps the asked transports and their processes; the totals stay those of all transports */
    protected function restrictToTransports(WorkersOverviewPayload $workersOverview, ?string $transport, bool $onlyActive): void
    {
        $workerTransportOverviews = new WorkerTransportOverviews();
        foreach ($workersOverview->transports->getElements() as $workerTransportOverview) {
            if ($transport !== null && $workerTransportOverview->transport !== $transport) {
                continue;
            }
            if ($onlyActive && !$this->isActive($workerTransportOverview)) {
                continue;
            }
            $workerTransportOverviews->add($workerTransportOverview);
        }
        $workerProcesses = new WorkerProcesses();
        foreach ($workersOverview->processes->getElements() as $workerProcess) {
            foreach ($workerTransportOverviews->getElements() as $workerTransportOverview) {
                if ($workerProcess->consumesTransport($workerTransportOverview->transport)) {
                    $workerProcesses->add($workerProcess);
                    break;
                }
            }
        }
        $workersOverview->transports = $workerTransportOverviews;
        $workersOverview->processes = $workerProcesses;
    }

    protected function isActive(WorkerTransportOverview $workerTransportOverview): bool
    {
        return $workerTransportOverview->state !== WorkerTransportOverview::STATE_IDLE
            || $workerTransportOverview->inFlight > 0
            || ($workerTransportOverview->waiting ?? 0) > 0
            || $workerTransportOverview->handledCount > 0
            || $workerTransportOverview->thrownCount > 0
            || $workerTransportOverview->caughtCount > 0;
    }
}

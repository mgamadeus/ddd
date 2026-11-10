<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerMessageClassStat;
use DDD\Domain\Common\Entities\Workers\WorkerTransportDetail;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:transport',
    description: 'Shows one transport in a time window: its state now, what its workers did per message class, its live processes and, with --sequences, every time series (handled, failed, duration, wait, waiting, in work, processes)'
)]
class WorkersTransport extends WorkersReport
{
    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::REQUIRED, 'The Messenger transport')
            ->addWindowOptions()
            ->addOption('sequences', null, InputOption::VALUE_NONE, 'Table output: also the time series, one row per step')
            ->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var WorkersOverviewService $workersOverviewService */
        $workersOverviewService = DDDService::instance()->getService(WorkersOverviewService::class);
        try {
            $windowEnd = $this->getWindowEnd($input);
            $windowStart = $this->getWindowStart($input, $windowEnd);
            /** @var WorkerTransportDetail $workerTransportDetail */
            $workerTransportDetail = $this->readWithoutRightsRestrictions(
                fn() => $workersOverviewService->getWorkerTransportDetail(
                    (string)$this->getStringArgument($input, 'transport'),
                    $windowStart,
                    $windowEnd,
                    $this->getStepSeconds($input)
                )
            );
        } catch (BadRequestException|NotFoundException $exception) {
            $symfonyStyle->getErrorStyle()->error($exception->getMessage());
            return Command::FAILURE;
        }
        if ($this->isJsonFormat($input)) {
            $this->writeJsonPayload($output, 'workerTransportDetail', $workerTransportDetail);
            return Command::SUCCESS;
        }
        $this->noteWhenMonitoringIsOff($symfonyStyle);
        $workerTransportOverview = $workerTransportDetail->transport;
        $symfonyStyle->text(sprintf(
            '%s · %s · waiting %s · in work %d · live processes %d · window %s – %s UTC, step %d s',
            $workerTransportOverview->transport,
            $workerTransportOverview->state,
            $workerTransportOverview->waiting ?? $workerTransportOverview->waitingState,
            $workerTransportOverview->inFlight,
            $workerTransportOverview->liveProcessCount,
            $this->formatDateTime($workerTransportDetail->interval->startDate),
            $this->formatDateTime($workerTransportDetail->interval->endDate),
            $workerTransportDetail->stepSeconds
        ));
        $symfonyStyle->table(
            ['Message class', 'Handled', 'Thrown', 'Caught', 'Redelivered', 'Avg ms', 'Max ms', 'Avg wait ms', 'Max wait ms', 'Last error'],
            array_map(
                fn(WorkerMessageClassStat $workerMessageClassStat): array => [
                    $workerMessageClassStat->messageClass,
                    $workerMessageClassStat->handledCount,
                    $workerMessageClassStat->thrownCount,
                    $workerMessageClassStat->caughtCount,
                    $workerMessageClassStat->redeliveredCount,
                    $workerMessageClassStat->durationAverageMs ?? '',
                    $workerMessageClassStat->durationMaxMs,
                    $workerMessageClassStat->waitAverageMs ?? '',
                    $workerMessageClassStat->waitMaxMs ?? '',
                    $workerMessageClassStat->lastErrorClass !== null
                        ? $this->formatDateTime($workerMessageClassStat->lastErrorAt) . ' ' . $workerMessageClassStat->lastErrorClass
                        : '',
                ],
                $workerTransportDetail->messageClasses->getElements()
            )
        );
        if (!$workerTransportDetail->processes->isEmpty()) {
            $this->renderWorkerProcessesTable($symfonyStyle, $workerTransportDetail->processes);
        }
        if ($input->getOption('sequences')) {
            $this->renderSequencesTable($symfonyStyle, $workerTransportDetail);
        }
        return Command::SUCCESS;
    }

    /** One row per step, one column per sequence; an empty cell is a gap */
    protected function renderSequencesTable(SymfonyStyle $symfonyStyle, WorkerTransportDetail $workerTransportDetail): void
    {
        $sequenceNames = [];
        $rowsByTimestamp = [];
        foreach ($workerTransportDetail->sequences->getElements() as $dateTimeValueSequence) {
            $sequenceNames[] = $dateTimeValueSequence->name;
            foreach ($dateTimeValueSequence->getElements() as $dateTimeValue) {
                $rowsByTimestamp[$dateTimeValue->x->getTimestamp()][$dateTimeValueSequence->name] = $dateTimeValue->y;
            }
        }
        ksort($rowsByTimestamp);
        $rows = [];
        foreach ($rowsByTimestamp as $timestamp => $valuesBySequenceName) {
            $row = [gmdate('Y-m-d H:i', $timestamp)];
            foreach ($sequenceNames as $sequenceName) {
                $row[] = $this->formatNumber($valuesBySequenceName[$sequenceName] ?? null);
            }
            $rows[] = $row;
        }
        $symfonyStyle->table(['Step (UTC)', ...$sequenceNames], $rows);
    }
}

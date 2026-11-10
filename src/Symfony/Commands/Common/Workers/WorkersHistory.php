<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;
use DDD\Domain\Common\Entities\Workers\WorkersHistory as WorkersHistoryPayload;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistories;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:history',
    description: 'Shows what the workers of every transport did in a time window: how many processes ran and started, what waited and was in work, what was handled and failed — per transport and in total; JSON carries the time series'
)]
class WorkersHistory extends WorkersReport
{
    protected function configure(): void
    {
        $this
            ->addWindowOptions()
            ->addOption('transport', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this transport (repeatable)')
            ->addOption(
                'sequence',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only this sequence (repeatable): ' . implode(', ', WorkersOverviewService::HISTORY_SEQUENCE_NAMES)
            )
            ->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var WorkersOverviewService $workersOverviewService */
        $workersOverviewService = DDDService::instance()->getService(WorkersOverviewService::class);
        /** @var string[] $transportNames */
        $transportNames = array_values(array_filter((array)$input->getOption('transport'), 'is_string'));
        /** @var string[] $sequenceNames */
        $sequenceNames = array_values(array_filter((array)$input->getOption('sequence'), 'is_string'));
        try {
            $unknownSequenceNames = array_diff($sequenceNames, WorkersOverviewService::HISTORY_SEQUENCE_NAMES);
            if ($unknownSequenceNames !== []) {
                throw new BadRequestException(sprintf(
                    'Unknown sequence %s: one of %s',
                    implode(', ', $unknownSequenceNames),
                    implode(', ', WorkersOverviewService::HISTORY_SEQUENCE_NAMES)
                ));
            }
            $windowEnd = $this->getWindowEnd($input);
            $windowStart = $this->getWindowStart($input, $windowEnd);
            /** @var WorkersHistoryPayload $workersHistory */
            $workersHistory = $this->readWithoutRightsRestrictions(
                fn() => $workersOverviewService->getWorkersHistory($windowStart, $windowEnd, $this->getStepSeconds($input))
            );
        } catch (BadRequestException $exception) {
            $symfonyStyle->getErrorStyle()->error($exception->getMessage());
            return Command::FAILURE;
        }
        $this->restrictToTransportsAndSequences($workersHistory, $transportNames, $sequenceNames);
        if ($this->isJsonFormat($input)) {
            $this->writeJsonPayload($output, 'workersHistory', $workersHistory);
            return Command::SUCCESS;
        }
        $this->noteWhenMonitoringIsOff($symfonyStyle);
        $shownSequenceNames = $sequenceNames !== [] ? $sequenceNames : WorkersOverviewService::HISTORY_SEQUENCE_NAMES;
        $symfonyStyle->text(sprintf(
            'Window %s – %s UTC, step %d s. Counts are sums; %s show the highest step.',
            $this->formatDateTime($workersHistory->interval->startDate),
            $this->formatDateTime($workersHistory->interval->endDate),
            $workersHistory->stepSeconds,
            implode(', ', array_intersect($shownSequenceNames, WorkerTransportHistory::GAP_SEQUENCES)) ?: 'measures'
        ));
        $rows = [];
        foreach ([...$workersHistory->transports->getElements(), $workersHistory->totals] as $workerTransportHistory) {
            $row = [$workerTransportHistory->transport !== '' ? $workerTransportHistory->transport : self::ALL_TRANSPORTS_LABEL];
            foreach ($shownSequenceNames as $sequenceName) {
                $row[] = $this->formatNumber($this->summarizeSequence($workerTransportHistory->getSequence($sequenceName)));
            }
            $rows[] = $row;
        }
        $symfonyStyle->table(['Transport', ...$shownSequenceNames], $rows);
        return Command::SUCCESS;
    }

    /**
     * @param string[] $transportNames Empty = every transport
     * @param string[] $sequenceNames Empty = every sequence
     */
    protected function restrictToTransportsAndSequences(WorkersHistoryPayload $workersHistory, array $transportNames, array $sequenceNames): void
    {
        if ($transportNames !== []) {
            $workerTransportHistories = new WorkerTransportHistories();
            foreach ($workersHistory->transports->getElements() as $workerTransportHistory) {
                if (in_array($workerTransportHistory->transport, $transportNames, true)) {
                    $workerTransportHistories->add($workerTransportHistory);
                }
            }
            $workersHistory->transports = $workerTransportHistories;
        }
        if ($sequenceNames === []) {
            return;
        }
        foreach ([...$workersHistory->transports->getElements(), $workersHistory->totals] as $workerTransportHistory) {
            $dateTimeValueSequences = new DateTimeValueSequences();
            foreach ($workerTransportHistory->sequences->getElements() as $dateTimeValueSequence) {
                /** @var DateTimeValueSequence $dateTimeValueSequence */
                if (in_array($dateTimeValueSequence->name, $sequenceNames, true)) {
                    $dateTimeValueSequences->add($dateTimeValueSequence);
                }
            }
            $workerTransportHistory->sequences = $dateTimeValueSequences;
        }
    }
}

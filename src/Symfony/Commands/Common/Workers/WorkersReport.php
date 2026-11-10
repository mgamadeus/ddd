<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Entities\Workers\WorkerThroughputWindow;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Domain\Common\Entities\Workers\WorkerTransportThroughputs;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base of the commands that read the worker monitoring from the console: the output format (`--format=table|json`,
 * JSON being the payload of the matching admin endpoint), the time window, and reading without rights restrictions.
 */
abstract class WorkersReport extends Command
{
    public const string FORMAT_TABLE = 'table';
    public const string FORMAT_JSON = 'json';
    public const int DEFAULT_SINCE_MINUTES = 60;
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 1000;
    public const string ALL_TRANSPORTS_LABEL = 'ALL TRANSPORTS';

    protected function addFormatOption(): static
    {
        return $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json (json: the payload of the matching admin endpoint)', self::FORMAT_TABLE);
    }

    protected function addWindowOptions(): static
    {
        return $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Window in minutes back from now (or from --to)', (string)self::DEFAULT_SINCE_MINUTES)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the window, UTC ("2026-01-31 10:00:00" or ISO 8601); instead of --since')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End of the window, UTC; default now')
            ->addOption('stepSeconds', null, InputOption::VALUE_REQUIRED, 'Bucket size of the sequences: 60, 300, 900 or 3600; default chosen by the window');
    }

    protected function isJsonFormat(InputInterface $input): bool
    {
        return $input->getOption('format') === self::FORMAT_JSON;
    }

    /** Writes the payload as the admin endpoint returns it: one object with the given property */
    protected function writeJsonPayload(OutputInterface $output, string $propertyName, object $payload): void
    {
        /** @var SerializerTrait $payload */
        $output->writeln((string)json_encode(
            [$propertyName => $payload->toObject(cached: false, forPersistence: false)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * @throws BadRequestException When `--to` is not a date-time
     */
    protected function getWindowEnd(InputInterface $input): DateTime
    {
        return $this->getDateTimeOption($input, 'to') ?? DateTime::fromTimestamp(time());
    }

    /**
     * @throws BadRequestException When `--from` is not a date-time
     */
    protected function getWindowStart(InputInterface $input, DateTime $windowEnd): DateTime
    {
        return $this->getDateTimeOption($input, 'from')
            ?? DateTime::fromTimestamp($windowEnd->getTimestamp() - $this->getSinceMinutes($input) * 60);
    }

    protected function getSinceMinutes(InputInterface $input): int
    {
        return max(1, (int)$input->getOption('since'));
    }

    protected function getStepSeconds(InputInterface $input): ?int
    {
        $stepSeconds = $input->getOption('stepSeconds');
        return is_numeric($stepSeconds) ? (int)$stepSeconds : null;
    }

    protected function getLimit(InputInterface $input): int
    {
        return min(self::MAX_LIMIT, max(1, (int)$input->getOption('limit')));
    }

    protected function getStringOption(InputInterface $input, string $optionName): ?string
    {
        $value = $input->getOption($optionName);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function getStringArgument(InputInterface $input, string $argumentName): ?string
    {
        $value = $input->getArgument($argumentName);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @throws BadRequestException When the option is not a date-time
     */
    protected function getDateTimeOption(InputInterface $input, string $optionName): ?DateTime
    {
        $value = $this->getStringOption($input, $optionName);
        if ($value === null) {
            return null;
        }
        $dateTime = (new TimeInterval())->stringToDateTime($value);
        if ($dateTime === null) {
            throw new BadRequestException(sprintf('--%s is not a date-time: %s', $optionName, $value));
        }
        return $dateTime;
    }

    /** A value as a quoted literal of a QueryOptions filter */
    protected function quoteFilterValue(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Runs the read with the rights restrictions switched off: the monitoring's tables belong to admin accounts and
     * a console run has no account.
     */
    protected function readWithoutRightsRestrictions(callable $read): mixed
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $read();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** One line on the error output when the monitoring is off: the tables hold nothing new */
    protected function noteWhenMonitoringIsOff(SymfonyStyle $symfonyStyle): void
    {
        if (!WorkersSettings::fromEnvironment()->enabled) {
            $symfonyStyle->getErrorStyle()->note('The worker monitoring is switched off (Common.Workers enabled / WORKERS_ENABLED): statistics, processes and message runs are not written.');
        }
    }

    protected function formatDateTime(?DateTime $dateTime): string
    {
        return $dateTime !== null ? gmdate('Y-m-d H:i:s', $dateTime->getTimestamp()) : '';
    }

    protected function formatNumber(int|float|null $number): string
    {
        return $number === null ? '' : (string)(is_float($number) && floor($number) === $number ? (int)$number : $number);
    }

    /**
     * What a sequence amounts to over its window: the sum of a count, the highest value of a measure that has gaps
     * ({@see WorkerTransportHistory::GAP_SEQUENCES}); null when no step has a value.
     */
    protected function summarizeSequence(?DateTimeValueSequence $dateTimeValueSequence): ?float
    {
        if ($dateTimeValueSequence === null) {
            return null;
        }
        $values = $dateTimeValueSequence->getValuesByTimestamp();
        if ($values === []) {
            return null;
        }
        return WorkerTransportHistory::isGapSequence($dateTimeValueSequence->name) ? (float)max($values) : (float)array_sum($values);
    }

    /**
     * One row per transport, one column per window: finished messages, their rate per minute, the rate per worker
     * minute (when processes were sampled) and the failed ones.
     */
    protected function renderThroughputTable(SymfonyStyle $symfonyStyle, WorkerTransportThroughputs $workerTransportThroughputs): void
    {
        $headers = ['Transport'];
        $rows = [];
        foreach ($workerTransportThroughputs->getElements() as $workerTransportThroughput) {
            $row = [$workerTransportThroughput->transport];
            foreach ($workerTransportThroughput->throughputWindows->getElements() as $workerThroughputWindow) {
                if ($rows === []) {
                    $headers[] = 'Finished ' . $this->formatWindow($workerThroughputWindow->windowSeconds);
                }
                $row[] = $this->formatThroughput($workerThroughputWindow);
            }
            $rows[] = $row;
        }
        $symfonyStyle->table($headers, $rows);
        $symfonyStyle->text('Finished messages · per minute · per worker minute · failed');
    }

    protected function formatThroughput(WorkerThroughputWindow $workerThroughputWindow): string
    {
        if ($workerThroughputWindow->handledCount === 0) {
            return '0';
        }
        $parts = [
            (string)$workerThroughputWindow->handledCount,
            $this->formatRate($workerThroughputWindow->getHandledPerMinute()) . '/min',
        ];
        if (($handledPerWorkerMinute = $workerThroughputWindow->getHandledPerWorkerMinute()) !== null) {
            $parts[] = $this->formatRate($handledPerWorkerMinute) . '/worker-min';
        }
        if ($workerThroughputWindow->failedCount > 0) {
            $parts[] = $workerThroughputWindow->failedCount . ' failed';
        }
        return implode(' · ', $parts);
    }

    protected function formatRate(?float $rate): string
    {
        return $rate === null ? '' : rtrim(rtrim(number_format($rate, $rate < 10 ? 2 : 1, '.', ''), '0'), '.');
    }

    /** 300 → 5m, 3600 → 1h, 86400 → 24h */
    protected function formatWindow(int $windowSeconds): string
    {
        return $windowSeconds % 3600 === 0 ? intdiv($windowSeconds, 3600) . 'h' : intdiv($windowSeconds, 60) . 'm';
    }

    protected function renderWorkerProcessesTable(SymfonyStyle $symfonyStyle, WorkerProcesses $workerProcesses): void
    {
        $symfonyStyle->table(
            ['Process', 'Transport', 'State', 'Started', 'Last seen', 'Handled', 'Failed', 'Current message', 'Stopped', 'Stop reason'],
            array_map(
                fn(WorkerProcess $workerProcess): array => [
                    $workerProcess->processKey ?? '',
                    $workerProcess->transport ?? '',
                    $workerProcess->displayState ?? $workerProcess->state ?? '',
                    $this->formatDateTime($workerProcess->startedAt ?? null),
                    $this->formatDateTime($workerProcess->lastSeenAt ?? null),
                    $workerProcess->handledCount,
                    $workerProcess->failedCount,
                    ($workerProcess->currentMessageClass ?? null) !== null
                        ? $workerProcess->currentMessageClass . ' since ' . $this->formatDateTime($workerProcess->currentMessageSince ?? null)
                        : '',
                    $this->formatDateTime($workerProcess->stoppedAt ?? null),
                    $workerProcess->stopReason ?? '',
                ],
                $workerProcesses->getElements()
            )
        );
    }
}

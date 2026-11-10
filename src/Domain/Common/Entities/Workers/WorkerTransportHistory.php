<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The time series of one transport in a window: one gap-free {@see DateTimeValueSequence} per measure, all with the
 * same step. The sequence names are the SEQUENCE_* constants.
 */
class WorkerTransportHistory extends ValueObject
{
    /** Messages that finished (whatever the outcome) */
    public const string SEQUENCE_HANDLED = 'handled';
    public const string SEQUENCE_THROWN = 'thrown';
    public const string SEQUENCE_CAUGHT = 'caught';
    /** Thrown + caught */
    public const string SEQUENCE_FAILED = 'failed';
    public const string SEQUENCE_REDELIVERED = 'redelivered';
    public const string SEQUENCE_DURATION_AVERAGE_MS = 'durationAverageMs';
    public const string SEQUENCE_DURATION_MAX_MS = 'durationMaxMs';
    public const string SEQUENCE_WAIT_AVERAGE_MS = 'waitAverageMs';
    public const string SEQUENCE_WAIT_MAX_MS = 'waitMaxMs';
    /** Messages waiting in the queue (highest sample of the step) */
    public const string SEQUENCE_WAITING = 'waiting';
    /** Messages in work (highest sample of the step) */
    public const string SEQUENCE_IN_FLIGHT = 'inFlight';
    /** Live worker processes (highest sample of the step) */
    public const string SEQUENCE_PROCESSES = 'processes';
    /** Worker processes that started in the step */
    public const string SEQUENCE_PROCESS_STARTS = 'processStarts';

    public string $transport = '';

    public DateTimeValueSequences $sequences;

    public function __construct(string $transport = '')
    {
        $this->transport = $transport;
        $this->sequences = new DateTimeValueSequences();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->transport);
    }

    /**
     * Adds a gap-free sequence of the window.
     *
     * @param array<int, int|float> $valuesByBucketStart Step-aligned Unix timestamp → value (an internal helper: the
     *     map comes straight from a query result)
     */
    public function addSequence(string $name, DateTime $from, DateTime $to, int $stepSeconds, array $valuesByBucketStart): void
    {
        $dateTimeValueSequence = DateTimeValueSequence::fromValuesByTimestamp($name, $from, $to, $stepSeconds, $valuesByBucketStart);
        $this->sequences->add($dateTimeValueSequence);
    }

    public function getSequence(string $name): ?DateTimeValueSequence
    {
        return $this->sequences->getByUniqueKey(DateTimeValueSequence::uniqueKeyStatic($name));
    }

    /** Takes over the sequences of another history of the same transport */
    public function addSequencesOf(WorkerTransportHistory $workerTransportHistory): void
    {
        foreach ($workerTransportHistory->sequences->getElements() as $dateTimeValueSequence) {
            $this->sequences->add($dateTimeValueSequence);
        }
    }
}

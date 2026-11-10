<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One transport as the workers overview shows it: its definition in the worker list, what waits and runs now, what
 * its workers did in the last minutes, and its state.
 */
class WorkerTransportOverview extends ValueObject
{
    /** Nothing waits, nothing runs */
    public const string STATE_IDLE = 'IDLE';
    /** Messages are in work or wait for a free process */
    public const string STATE_WORKING = 'WORKING';
    /** Messages wait and every live process is busy, or none is live yet */
    public const string STATE_BACKLOG = 'BACKLOG';
    /** Messages wait and no process has been live for longer than the configured time */
    public const string STATE_STUCK = 'STUCK';
    /** The Messenger configuration consumes the transport, the worker list has no entry for it */
    public const string STATE_UNDEFINED = 'UNDEFINED';
    /** The worker list has an entry, the Messenger configuration has no such transport */
    public const string STATE_NOT_CONSUMED = 'NOT_CONSUMED';

    public string $transport = '';

    /** @var bool The worker list has a consumer entry for the transport */
    public bool $isDefined = false;

    public ?WorkerConsumerDefinition $definition = null;

    public bool $isAutoscaled = false;

    /** @var int|null Fewest worker processes the definition allows; null when undefined */
    public ?int $effectiveMin = null;

    /** @var int|null Most worker processes the definition allows; null when undefined */
    public ?int $effectiveMax = null;

    /** @var string How the waiting count was obtained: a {@see WorkerQueueCount} state */
    #[Choice(choices: [WorkerQueueCount::STATE_COUNTED, WorkerQueueCount::STATE_UNKNOWN, WorkerQueueCount::STATE_QUEUE_NOT_SET_UP])]
    public string $waitingState = WorkerQueueCount::STATE_UNKNOWN;

    /** @var int|null Messages waiting in the queue; null unless the waiting state is COUNTED */
    public ?int $waiting = null;

    /** @var DateTime|null When the waiting count was read */
    public ?DateTime $waitingSampledAt = null;

    /** @var int Messages in work */
    public int $inFlight = 0;

    public int $liveProcessCount = 0;

    /** @var string One of the STATE_* constants */
    #[Choice(choices: [
        self::STATE_IDLE, self::STATE_WORKING, self::STATE_BACKLOG, self::STATE_STUCK, self::STATE_UNDEFINED, self::STATE_NOT_CONSUMED,
    ])]
    public string $state = self::STATE_IDLE;

    /** @var DateTime|null Since when messages wait without a live process; set only in state STUCK */
    public ?DateTime $stuckSince = null;

    /** @var bool A restart was requested and a live process has not stopped for it yet */
    public bool $restartPending = false;

    /** @var int Messages finished in the recent window ({@see WorkersOverview::$recentWindowSeconds}, 15 minutes) */
    public int $handledCount = 0;

    public int $thrownCount = 0;

    public int $caughtCount = 0;

    /** @var int|null Average handling time in the recent window; null without a message */
    public ?int $durationAverageMs = null;

    /** @var int|null Average wait in the recent window; null when no message carried its dispatch time */
    public ?int $waitAverageMs = null;

    /**
     * @var WorkerThroughputWindows What was finished and failed in the last 5 minutes, 15 minutes, hour and day, each
     * with the worker seconds of the window: count ÷ minutes = rate, count ÷ worker minutes = rate per worker
     */
    public WorkerThroughputWindows $throughputWindows;

    /** @var string|null Last error of the last hour */
    public ?string $lastErrorClass = null;

    public ?string $lastErrorMessage = null;

    public ?DateTime $lastErrorAt = null;

    /** @var DateTimeValueSequences The last hour in minutes: handled, failed, waiting, processes (the last two null in a minute without a sample) */
    public DateTimeValueSequences $sequences;

    public function __construct(string $transport = '')
    {
        $this->transport = $transport;
        $this->sequences = new DateTimeValueSequences();
        $this->throughputWindows = WorkerThroughputWindows::fromWindowSeconds();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->transport);
    }
}

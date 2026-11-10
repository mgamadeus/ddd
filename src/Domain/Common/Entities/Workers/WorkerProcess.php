<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerProcess;
use DDD\Domain\Common\Services\WorkerProcessesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One worker process (`messenger:consume`): registered when it starts, kept alive by its heartbeat, closed with a stop
 * reason when it stops — and kept afterwards as the history of spawned workers.
 *
 * The process writes its own row; another process only ever sets `restartRequestedAt` (a restart request) or, for a
 * process that vanished without a stop, `state` STOPPED with stop reason LOST. A worker cannot beat while a handler
 * runs: a process with a current message is busy, not lost ({@see self::getDisplayState()}).
 *
 * @method static WorkerProcessesService getService()
 * @method static DBWorkerProcess getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerProcess::class)]
#[QueryOptions]
class WorkerProcess extends Entity
{
    use QueryOptionsTrait;

    public const string STATE_RUNNING = 'RUNNING';
    public const string STATE_STOPPING = 'STOPPING';
    public const string STATE_STOPPED = 'STOPPED';

    /** Derived states of {@see self::getDisplayState()} */
    public const string DISPLAY_STATE_BUSY = 'BUSY';
    public const string DISPLAY_STATE_SILENT = 'SILENT';
    public const string DISPLAY_STATE_LOST = 'LOST';

    public const string STOP_REASON_MESSAGE_LIMIT = 'MESSAGE_LIMIT';
    public const string STOP_REASON_TIME_LIMIT = 'TIME_LIMIT';
    public const string STOP_REASON_MEMORY_LIMIT = 'MEMORY_LIMIT';
    public const string STOP_REASON_FAILURE_LIMIT = 'FAILURE_LIMIT';
    public const string STOP_REASON_RESTART_REQUESTED = 'RESTART_REQUESTED';
    public const string STOP_REASON_SIGNAL = 'SIGNAL';
    public const string STOP_REASON_OTHER = 'OTHER';
    /** No stop event and no heartbeat: killed, out of memory, host gone */
    public const string STOP_REASON_LOST = 'LOST';

    /** Separator of the transport names of a process that consumes more than one */
    public const string TRANSPORT_SEPARATOR = ',';

    /** @var string|null Host name, process id and start time: unique under an orchestrator (host = pod) and a supervisor */
    #[NotNull]
    #[Length(max: 191)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public ?string $processKey;

    /** @var string|null Host name (the pod name under an orchestrator) */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $hostName;

    /** @var int|null Operating-system process id */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $processId;

    /** @var string|null The consumed transport; several names joined by "," when the process consumes more than one */
    #[NotNull]
    #[Length(max: 255)]
    public ?string $transport;

    /** @var DateTime|null Start of the worker */
    #[NotNull]
    public ?DateTime $startedAt;

    /** @var DateTime|null Start of the first message; null = never got one */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'COALESCE(`firstMessageAt`, VALUES(`firstMessageAt`))')]
    public ?DateTime $firstMessageAt;

    /** @var DateTime|null Last heartbeat */
    #[NotNull]
    public ?DateTime $lastSeenAt;

    /** @var string|null RUNNING, STOPPING (a stop was requested, the current message finishes) or STOPPED */
    #[NotNull]
    #[Choice(choices: [self::STATE_RUNNING, self::STATE_STOPPING, self::STATE_STOPPED])]
    #[Length(max: 16)]
    public ?string $state;

    /** @var DateTime|null End of the process (for a lost one: when it was noticed) */
    public ?DateTime $stoppedAt;

    /** @var string|null Why the process stopped, as far as it can be told (one of the STOP_REASON_* constants) */
    #[Choice(choices: [
        self::STOP_REASON_MESSAGE_LIMIT, self::STOP_REASON_TIME_LIMIT, self::STOP_REASON_MEMORY_LIMIT,
        self::STOP_REASON_FAILURE_LIMIT, self::STOP_REASON_RESTART_REQUESTED, self::STOP_REASON_SIGNAL,
        self::STOP_REASON_OTHER, self::STOP_REASON_LOST,
    ])]
    #[Length(max: 32)]
    public ?string $stopReason;

    /** @var int Messages the process finished */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $handledCount = 0;

    /** @var int Of those, messages that failed (thrown or caught) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $failedCount = 0;

    /** @var int|null Memory in use at the last heartbeat, in bytes */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $memoryBytes;

    /** @var int|null Highest memory use of the process, in bytes */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $memoryPeakBytes;

    /** @var string|null Class of the message in work; null = idle */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $currentMessageClass;

    /** @var DateTime|null Start of the message in work */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $currentMessageSince;

    /** @var DateTime|null A restart was requested: the process stops after its current message. Never written by the process itself */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $restartRequestedAt;

    /** @var string|null Not stored: {@see self::getDisplayState()} at the time the row was read */
    #[DatabaseColumn(ignoreProperty: true)]
    public ?string $displayState = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->processKey ?? $this->id ?? spl_object_id($this));
    }

    /** @return string[] The transports the process consumes */
    public function getTransportNames(): array
    {
        return array_values(array_filter(explode(self::TRANSPORT_SEPARATOR, $this->transport ?? '')));
    }

    public function consumesTransport(string $transport): bool
    {
        return in_array($transport, $this->getTransportNames(), true);
    }

    /**
     * The state to show: the stored one, refined by the heartbeat. A stopped process is STOPPED (LOST when it never
     * reported a stop). A running one with a current message is BUSY — it cannot beat while its handler runs — and
     * SILENT once that message runs longer than $busySilentSeconds; an idle one without a beat for $lostAfterSeconds
     * is LOST.
     */
    public function getDisplayState(DateTime $now, int $lostAfterSeconds, int $busySilentSeconds): string
    {
        if (($this->state ?? null) === self::STATE_STOPPED) {
            return ($this->stopReason ?? null) === self::STOP_REASON_LOST ? self::DISPLAY_STATE_LOST : self::STATE_STOPPED;
        }
        $nowTimestamp = $now->getTimestamp();
        if (($this->currentMessageClass ?? null) !== null) {
            $busySince = isset($this->currentMessageSince) ? $this->currentMessageSince->getTimestamp() : $nowTimestamp;
            return $nowTimestamp - $busySince > $busySilentSeconds ? self::DISPLAY_STATE_SILENT : self::DISPLAY_STATE_BUSY;
        }
        $lastSeen = isset($this->lastSeenAt) ? $this->lastSeenAt->getTimestamp() : 0;
        if ($nowTimestamp - $lastSeen > $lostAfterSeconds) {
            return self::DISPLAY_STATE_LOST;
        }
        return $this->state ?? self::STATE_RUNNING;
    }

    /** @return bool The process counts as alive: running or busy (not stopped, not lost, not silent) */
    public function isLive(DateTime $now, int $lostAfterSeconds, int $busySilentSeconds): bool
    {
        return in_array(
            $this->getDisplayState($now, $lostAfterSeconds, $busySilentSeconds),
            [self::STATE_RUNNING, self::STATE_STOPPING, self::DISPLAY_STATE_BUSY],
            true
        );
    }
}

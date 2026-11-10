<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerTransportSample;
use DDD\Domain\Common\Services\WorkerTransportSamplesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The state of one transport's queue and workers in one minute: the history behind the backlog and worker charts.
 * Two writers, merged by upsert on (transport, bucketStart): the heartbeat of the transport's workers (peaks inside the
 * minute) and the sampler command `app:workers:sample` (every transport once per run, also one nobody consumes). The
 * `…Max` columns keep the highest value of the minute, the `…Last` columns the most recent one.
 *
 * `waiting…` is null when the transport's receiver cannot count or its queue does not exist yet.
 *
 * @method static WorkerTransportSamplesService getService()
 * @method static DBWorkerTransportSample getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerTransportSample::class)]
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['transport', 'bucketStart'])]
class WorkerTransportSample extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null The Messenger transport */
    #[NotNull]
    #[Length(max: 64)]
    public ?string $transport;

    /** @var DateTime|null Start of the minute (UTC) */
    #[NotNull]
    public ?DateTime $bucketStart;

    /** @var int|null Most messages waiting (ready, not yet delivered to a worker) seen in the minute */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'COALESCE(GREATEST(`waitingMax`, VALUES(`waitingMax`)), VALUES(`waitingMax`), `waitingMax`)')]
    public ?int $waitingMax;

    /** @var int|null Messages waiting at the last sample of the minute */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'COALESCE(VALUES(`waitingLast`), `waitingLast`)')]
    public ?int $waitingLast;

    /** @var int Most messages in work (live processes with a current message) seen in the minute */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'GREATEST(`inFlightMax`, VALUES(`inFlightMax`))')]
    public int $inFlightMax = 0;

    /** @var int Messages in work at the last sample of the minute */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $inFlightLast = 0;

    /** @var int Most live worker processes seen in the minute */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'GREATEST(`processCountMax`, VALUES(`processCountMax`))')]
    public int $processCountMax = 0;

    /** @var int Live worker processes at the last sample of the minute */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $processCountLast = 0;

    /** @var DateTime|null Time of the last sample of the minute */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $lastSampledAt;

    /** @var DateTime|null Time of the last sample the sampler command wrote; null = only heartbeats wrote this minute */
    #[DatabaseColumn(onUpdateAction: 'COALESCE(VALUES(`lastSampledByCommandAt`), `lastSampledByCommandAt`)')]
    public ?DateTime $lastSampledByCommandAt;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(
            ($this->transport ?? '') . '|' . (isset($this->bucketStart) ? (string)$this->bucketStart : spl_object_id($this))
        );
    }
}

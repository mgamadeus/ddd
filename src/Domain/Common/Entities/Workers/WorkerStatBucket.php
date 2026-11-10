<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerStatBucket;
use DDD\Domain\Common\Services\WorkerStatBucketsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * What the workers of one transport did with one message class in one minute. Written by the worker monitoring in
 * batches: every write is an additive upsert on (transport, messageClass, bucketStart) — counts and totals are added,
 * maxima kept, the last error replaced — so any number of processes write the same row without reading it.
 *
 * A message counts once: `handledCount` is every finished message, of which `thrownCount` left the handler with an
 * exception and `caughtCount` were logged by the handler and returned. failed = thrown + caught.
 *
 * @method static WorkerStatBucketsService getService()
 * @method static DBWorkerStatBucket getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerStatBucket::class)]
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['transport', 'messageClass', 'bucketStart'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['transport', 'bucketStart'])]
class WorkerStatBucket extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null The Messenger transport the messages were consumed from */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $transport;

    /** @var string|null Class of the message */
    #[NotNull]
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $messageClass;

    /** @var DateTime|null Start of the minute (UTC) */
    #[NotNull]
    public ?DateTime $bucketStart;

    /** @var int Finished messages, whatever the outcome */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: '`handledCount` + VALUES(`handledCount`)')]
    public int $handledCount = 0;

    /** @var int Messages whose handler threw (Messenger retries or fails them) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: '`thrownCount` + VALUES(`thrownCount`)')]
    public int $thrownCount = 0;

    /** @var int Messages whose handler caught an error, logged it and returned */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: '`caughtCount` + VALUES(`caughtCount`)')]
    public int $caughtCount = 0;

    /** @var int Messages that were a redelivery (a retry of an earlier attempt) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: '`redeliveredCount` + VALUES(`redeliveredCount`)')]
    public int $redeliveredCount = 0;

    /** @var int Sum of the handling durations in milliseconds */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT, onUpdateAction: '`durationTotalMs` + VALUES(`durationTotalMs`)')]
    public int $durationTotalMs = 0;

    /** @var int Longest handling duration in milliseconds */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'GREATEST(`durationMaxMs`, VALUES(`durationMaxMs`))')]
    public int $durationMaxMs = 0;

    /** @var int Messages whose queue wait is known (dispatch or redelivery time present) */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: '`waitCount` + VALUES(`waitCount`)')]
    public int $waitCount = 0;

    /** @var int Sum of the queue waits (dispatch → start of handling) in milliseconds, over `waitCount` messages */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT, onUpdateAction: '`waitTotalMs` + VALUES(`waitTotalMs`)')]
    public int $waitTotalMs = 0;

    /** @var int Longest queue wait in milliseconds */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT, onUpdateAction: 'GREATEST(`waitMaxMs`, VALUES(`waitMaxMs`))')]
    public int $waitMaxMs = 0;

    /** @var string|null Class of the last error (thrown or caught) */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'COALESCE(VALUES(`lastErrorClass`), `lastErrorClass`)')]
    public ?string $lastErrorClass;

    /** @var string|null Message of the last error, shortened */
    #[Length(max: 500)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VARCHAR, varCharLength: 500, onUpdateAction: 'COALESCE(VALUES(`lastErrorMessage`), `lastErrorMessage`)')]
    public ?string $lastErrorMessage;

    /** @var DateTime|null Time of the last error */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: 'COALESCE(VALUES(`lastErrorAt`), `lastErrorAt`)')]
    public ?DateTime $lastErrorAt;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(
            ($this->transport ?? '') . '|' . ($this->messageClass ?? '') . '|' . (isset($this->bucketStart) ? (string)$this->bucketStart : spl_object_id($this))
        );
    }

    /** @return int Messages that failed: thrown or caught */
    public function getFailedCount(): int
    {
        return $this->thrownCount + $this->caughtCount;
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Workers\DBWorkerMessageRun;
use DDD\Domain\Common\Services\WorkerMessageRunsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One consumed message: where it came from, how long it waited and ran, how it ended. The log behind the statistics —
 * written in batches (one multi-row insert per flush), never with the message's payload: failure context is in the
 * issues log, linked by `issueGroupHash`.
 *
 * @method static WorkerMessageRunsService getService()
 * @method static DBWorkerMessageRun getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBWorkerMessageRun::class)]
#[QueryOptions]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['transport', 'startedAt'])]
class WorkerMessageRun extends Entity
{
    use QueryOptionsTrait;

    /** The handler returned without reporting an error */
    public const string OUTCOME_HANDLED = 'HANDLED';
    /** The handler caught an error, logged it and returned */
    public const string OUTCOME_CAUGHT = 'CAUGHT';
    /** An exception left the handler: Messenger retries or fails the message */
    public const string OUTCOME_THROWN = 'THROWN';
    public const array FAILED_OUTCOMES = [self::OUTCOME_CAUGHT, self::OUTCOME_THROWN];

    /** @var string|null The Messenger transport the message was consumed from */
    #[NotNull]
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $transport;

    /** @var string|null Class of the message */
    #[NotNull]
    #[Length(max: 255)]
    public ?string $messageClass;

    /** @var string|null {@see WorkerProcess::$processKey} of the consuming process; null for a message run from the command line */
    #[Length(max: 191)]
    public ?string $processKey;

    /** @var DateTime|null When the message was dispatched; null when it carries no dispatch origin */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $dispatchedAt;

    /** @var string|null Route of the request that dispatched the message */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $dispatchedFromRoute;

    /** @var string|null Console command that dispatched the message */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $dispatchedFromCommand;

    /** @var DateTime|null Start of the handling */
    #[NotNull]
    public ?DateTime $startedAt;

    /** @var int Handling duration in milliseconds */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $durationMs = 0;

    /** @var int|null Queue wait in milliseconds: dispatch (for a retry: redelivery) → start; null when that time is unknown */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $waitMs;

    /** @var string|null HANDLED, CAUGHT or THROWN */
    #[NotNull]
    #[Choice(choices: [self::OUTCOME_HANDLED, self::OUTCOME_CAUGHT, self::OUTCOME_THROWN])]
    #[Length(max: 16)]
    public ?string $outcome;

    /** @var int Delivery attempt: 1 = first delivery */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public int $attempt = 1;

    /** @var bool|null A thrown message: Messenger retries it; null when not thrown or unknown */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?bool $willRetry;

    /** @var string|null Class of the error */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $errorClass;

    /** @var string|null Message of the error, shortened */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_VARCHAR, varCharLength: 500)]
    public ?string $errorMessage;

    /** @var string|null Group hash of the error in the issues log, when it was logged there */
    #[Length(max: 64)]
    public ?string $issueGroupHash;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }

    public function isFailed(): bool
    {
        return in_array($this->outcome ?? null, self::FAILED_OUTCOMES, true);
    }
}

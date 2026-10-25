<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ConflictRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\UpsertConflictKey;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBConflictAttributeRow;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Same ambiguous shape as {@see AmbiguousConflictRow} (two independent UNIQUE keys, both always set), plus the
 * attribute that disambiguates it: `handle` is declared as the one key this entity's upsert relies on being
 * merged — mirrors the real Dineful `DeviceRegistration`, whose `#[UpsertConflictKey(columns: ['tokenHash',
 * 'audience'])]` pins the dedup key over its `uuid` public handle.
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['handle'])]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['code'])]
#[UpsertConflictKey(columns: ['handle'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBConflictAttributeRow::class)]
#[QueryOptions]
class ConflictAttributeRow extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null The declared `ON CONFLICT` arbiter */
    #[NotNull]
    public ?string $handle;

    /** @var string|null The other candidate UNIQUE key — never the arbiter */
    #[NotNull]
    public ?string $code;

    /** @var string|null Free text, to see which row an update actually touched */
    public ?string $label;
}

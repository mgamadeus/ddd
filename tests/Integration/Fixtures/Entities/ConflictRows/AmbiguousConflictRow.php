<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ConflictRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBAmbiguousConflictRow;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Two independent UNIQUE keys, no `#[UpsertConflictKey]` — the `DeviceRegistration` shape (its own
 * `(tokenHash, audience)` + `uuid` keys) without the attribute that disambiguates it, so every no-id save hits
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\Write\ConflictTargetResolver}'s case-4 "ambiguous arbiter" exception on
 * PostgreSQL (MariaDB has no such ambiguity — ODKU fires on whichever key actually collides).
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['handle'])]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['code'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAmbiguousConflictRow::class)]
#[QueryOptions]
class AmbiguousConflictRow extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null One candidate UNIQUE key, always set — NOT-NULL-backed, like `DeviceRegistration`'s keys */
    #[NotNull]
    public ?string $handle;

    /** @var string|null The other candidate UNIQUE key, always set — NOT-NULL-backed */
    #[NotNull]
    public ?string $code;

    /** @var string|null Free text, to see which row an update actually touched */
    public ?string $label;
}

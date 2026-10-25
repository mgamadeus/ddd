<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TriggerRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TriggerRows\DBTriggerRows;

/**
 * Exists only so {@see \DDD\Domain\Base\Entities\Traits\EntityTrait::getEntitySetClass()}'s pluralising lookup
 * resolves {@see TriggerRow}'s table name to "TriggerRows" (inflector convention, same as every other fixture's
 * `{Entity}Row` / `{Entity}Rows` pair) — nothing in this fixture's test touches it as a set.
 *
 * @property TriggerRow[] $elements
 * @method TriggerRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBTriggerRows::class)]
class TriggerRows extends EntitySet
{
    use QueryOptionsTrait;
}

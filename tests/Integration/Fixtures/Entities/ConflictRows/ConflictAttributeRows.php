<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ConflictRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBConflictAttributeRows;
use DDD\Tests\Integration\Fixtures\Services\ConflictAttributeRowsService;

/**
 * @property ConflictAttributeRow[] $elements
 * @method ConflictAttributeRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBConflictAttributeRows::class)]
class ConflictAttributeRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = ConflictAttributeRowsService::class;
}

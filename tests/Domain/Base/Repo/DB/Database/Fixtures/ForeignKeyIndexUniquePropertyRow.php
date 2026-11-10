<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;

/** A row with a foreign key column, see ForeignKeyIndexTest */
class ForeignKeyIndexUniquePropertyRow extends Entity
{
    /** @var int|null The parent's id */
    #[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE)]
    public ?int $tieBreakerParentId = null;

    /** @var TieBreakerParent|null The parent */
    #[LazyLoad(LazyLoadRepo::DB)]
    public ?TieBreakerParent $tieBreakerParent;

    /** @var string|null A label */
    public ?string $label = null;
}

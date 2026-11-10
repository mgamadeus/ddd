<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\PairRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Tests\Integration\Fixtures\Repo\DB\PairRows\DBPairDetail;
use Symfony\Component\Validator\Constraints\NotNull;

/** The owning side of a one-to-one relation: at most one detail row per {@see PairParent}. */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBPairDetail::class)]
class PairDetail extends Entity
{
    use QueryOptionsTrait;

    /** @var int The parent's id */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public int $pairParentId;

    /** @var PairParent The parent */
    #[LazyLoad(LazyLoadRepo::DB, addAsParent: true)]
    public PairParent $pairParent;

    /** @var string|null The detail's content */
    public ?string $content;

    /** @var bool Whether the detail is approved */
    public bool $approved = true;
}

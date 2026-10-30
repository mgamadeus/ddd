<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerChild;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * To-many child of {@see TieBreakerParent}, itself the parent of a to-many {@see TieBreakerGrandchild} collection
 * — see {@see TieBreakerParent}'s docblock for why this fixture exists and where its tables/models live.
 * `#[LazyLoad(addAsParent: true)]` on {@see self::$tieBreakerParent} (the {@see TieBreakerParent}-typed FK property,
 * mirrors `CronExecution::$cron`) is what makes the generator emit the real Doctrine `#[ORM\ManyToOne]`/
 * `#[ORM\OneToMany]` pair {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\DefaultOrderTreeWalker} needs to recognise
 * `TieBreakerParent::$children` as a to-many association join.
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBTieBreakerChild::class)]
class TieBreakerChild extends Entity
{
    use QueryOptionsTrait;

    /** @var int The owning TieBreakerParent's id */
    #[NotNull]
    public int $tieBreakerParentId;

    /** @var TieBreakerParent The owning parent row */
    #[LazyLoad(LazyLoadRepo::DB, addAsParent: true)]
    public TieBreakerParent $tieBreakerParent;

    /** @var string|null Free label (fixtures set it to the intended id-ascending position, for readable assertion failures) */
    public ?string $label;

    /** @var TieBreakerGrandchildren The to-many grandchildren of this child */
    #[LazyLoad(LazyLoadRepo::DB)]
    public TieBreakerGrandchildren $tieBreakerGrandchildren;
}

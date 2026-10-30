<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerGrandchild;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Leaf to-many child of {@see TieBreakerChild} — see {@see TieBreakerParent}'s docblock for why this fixture
 * exists. Grandchild-level nesting is what proves the tie-breaker chain is appended `root → child → grandchild`,
 * not just one level deep.
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBTieBreakerGrandchild::class)]
class TieBreakerGrandchild extends Entity
{
    use QueryOptionsTrait;

    /** @var int The owning TieBreakerChild's id */
    #[NotNull]
    public int $tieBreakerChildId;

    /** @var TieBreakerChild The owning child row */
    #[LazyLoad(LazyLoadRepo::DB, addAsParent: true)]
    public TieBreakerChild $tieBreakerChild;

    /** @var string|null Free label (fixtures set it to the intended id-ascending position) */
    public ?string $label;
}

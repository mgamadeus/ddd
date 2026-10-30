<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Tests\Integration\Fixtures\Repo\DB\StoredBaseRows\DBStoredBaseChild;

/**
 * A nullable parent FK inside a composite UNIQUE index — the shape of Radbonus' Journals / GoalAchievements: the
 * index covers `IFNULL(storedBaseParentId, 0)` STORED, so the FK column is the base of a stored generated column.
 * The FK declares CASCADE for both actions; what each dialect gets is
 * {@see DatabaseForeignKey::getOnUpdateActionForDialect()}.
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['label', 'virtualStoredBaseParentId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBStoredBaseChild::class)]
class StoredBaseChild extends Entity
{
    /** @var int|null Null and 0 are one key in the unique index */
    #[DatabaseVirtualColumn(as: '(IFNULL(storedBaseParentId, 0))', stored: true)]
    public ?int $storedBaseParentId = null;

    #[LazyLoad(LazyLoadRepo::DB, addAsParent: true)]
    #[DatabaseForeignKey(onUpdateAction: DatabaseForeignKey::ACTION_CASCADE, onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?StoredBaseParent $storedBaseParent = null;

    public ?string $label = null;
}

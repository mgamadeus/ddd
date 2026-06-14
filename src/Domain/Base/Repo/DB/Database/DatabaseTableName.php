<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Overrides the auto-generated SQL table name for an Entity (or its EntitySet).
 *
 * By default {@see DatabaseModel::fromEntityClass()} derives the table name as
 * `DATABASE_TABLE_PREFIX` + EntitySet class name (e.g. prefix `Entity` → `EntityAIConversationMessages`).
 * Annotating the Entity (preferred) or its EntitySet with this attribute replaces that derived name with the
 * verbatim `name` given here — the global `DATABASE_TABLE_PREFIX` is NOT applied on top.
 *
 * Use it to point a subset of entities at a different set of tables (e.g. an isolated working set during an
 * in-flight structural refactor) without touching the rest of the schema:
 *
 *   #[DatabaseTableName('AdoAIConversationMessages')]
 *   class AIConversationMessage extends Entity { … }
 *
 * Honoured for both the entity's own table and foreign-key references pointing to it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class DatabaseTableName extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string The verbatim SQL table name to use, replacing the prefix + EntitySet-name default. */
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
        parent::__construct();
    }
}

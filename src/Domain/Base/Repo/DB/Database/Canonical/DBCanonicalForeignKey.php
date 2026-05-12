<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Canonical snapshot of a single foreign-key constraint. Matched between live and target by
 * (internalIdColumn, foreignTable, foreignIdColumn) — the constraint name is auto-generated and
 * therefore unstable.
 *
 * `constraintName` is populated only on the live side; on target it stays null because DDD
 * derives the name at SQL-render time.
 */
class DBCanonicalForeignKey extends ValueObject
{
    /** @var string Synthetic key, e.g. "personId->Persons.id". Stable across both sides. */
    public string $matchKey;

    public string $internalIdColumn;

    public string $foreignTable;

    public string $foreignIdColumn;

    /** @var string One of DatabaseForeignKey::ACTION_* */
    public string $onUpdateAction;

    /** @var string One of DatabaseForeignKey::ACTION_* */
    public string $onDeleteAction;

    /** @var string|null Live-side constraint name. Null on target side. */
    public ?string $constraintName = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->matchKey);
    }
}

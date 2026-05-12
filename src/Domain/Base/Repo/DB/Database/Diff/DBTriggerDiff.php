<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalTrigger;

/**
 * Describes a single trigger-level delta.
 *
 * Triggers are matched between target and current by trigger name. Body changes between matched
 * triggers force a MODIFY (DROP + CREATE) because MySQL/MariaDB cannot ALTER a trigger's body in
 * place — the only way to update it is to drop and recreate it.
 *
 * Triggers run before/after row events. The phase assembler drops every affected trigger first
 * (so DDL on the table is not blocked or perturbed by stale BEFORE triggers) and recreates them
 * last (so their bodies always reference columns in their final shape).
 */
class DBTriggerDiff extends ValueObject
{
    public const string CHANGE_KIND_ADD = 'ADD';

    public const string CHANGE_KIND_DROP = 'DROP';

    public const string CHANGE_KIND_MODIFY = 'MODIFY';

    /** @var string Live trigger name (and the name the target SQL declares). */
    public string $triggerName;

    /** @var string SQL table the trigger fires on. */
    public string $tableName;

    /** @var string One of CHANGE_KIND_*. */
    public string $changeKind;

    /** @var string|null Raw CREATE TRIGGER statement(s) from the target side. Null for DROP. */
    public ?string $targetSql = null;

    /** @var DBCanonicalTrigger|null Live trigger snapshot. Null for ADD. */
    public ?DBCanonicalTrigger $currentDefinition = null;

    /**
     * @var string DROP TRIGGER, CREATE TRIGGER, or both (DROP;\nCREATE) for MODIFY. The phase
     * assembler splits the MODIFY halves into its drop/create phases when executing.
     */
    public string $sql = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->tableName . '.' . $this->triggerName);
    }
}

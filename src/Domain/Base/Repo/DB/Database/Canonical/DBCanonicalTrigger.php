<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Canonical snapshot of a single trigger.
 *
 * Asymmetric by design: the live side carries a parsed action_statement (from
 * INFORMATION_SCHEMA.TRIGGERS), while the target side carries the raw CREATE TRIGGER source SQL
 * extracted from a .sql file. Both go through normaliseTriggerBody() before comparison so
 * whitespace, casing and BEGIN…END wrappers do not falsely flag changes.
 */
class DBCanonicalTrigger extends ValueObject
{
    public string $triggerName;

    public string $tableName;

    /** @var string|null BEFORE / AFTER — live side only (target side carries it inside rawSql). */
    public ?string $timing = null;

    /** @var string|null INSERT / UPDATE / DELETE — live side only. */
    public ?string $event = null;

    /** @var string|null Live action_statement (raw body). Null on target side. */
    public ?string $actionStatement = null;

    /** @var string|null Full CREATE TRIGGER … statement from the source .sql file. Target side only. */
    public ?string $rawSql = null;

    /** @var string|null Body normalised to a comparable form. Populated on both sides. */
    public ?string $normalisedBody = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->tableName . '.' . $this->triggerName);
    }
}

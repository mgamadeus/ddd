<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;

/**
 * Describes a single column-level delta between the code-derived schema (target)
 * and the live database (current).
 *
 * One instance represents one ADD/DROP/MODIFY operation. The container set
 * {@see DBColumnDiffs} groups all column-level diffs for a single table.
 */
class DBColumnDiff extends ValueObject
{
    public const string CHANGE_KIND_ADD = 'ADD';

    public const string CHANGE_KIND_DROP = 'DROP';

    public const string CHANGE_KIND_MODIFY = 'MODIFY';

    /** @var string Column name (same on both sides for MODIFY). */
    public string $columnName;

    /** @var string One of CHANGE_KIND_*. */
    public string $changeKind;

    /**
     * @var DatabaseColumn|null Target-side definition. Null for DROP. Used internally for SQL
     * rendering and severity classification. Excluded from:
     *  - JSON output (`#[HideProperty]`) — frontend gets canonical currentDefinition + rendered sql instead.
     *  - OpenAPI schema (`#[Ignore]`) — the rich attribute graph (Choice with dynamic choices,
     *    LazyLoad, encryption scopes, …) is not introspectable and crashes the autodocumenter.
     */
    #[HideProperty]
    #[Ignore]
    public ?DatabaseColumn $targetColumn = null;

    /**
     * @var DBCanonicalColumn|null Canonical snapshot of the live column. Null for ADD.
     */
    public ?DBCanonicalColumn $currentDefinition = null;

    /**
     * @var string[] For MODIFY: names of the canonical attributes that differ
     * (e.g. ['sqlType', 'allowsNull']). Empty for ADD/DROP.
     */
    public array $changedAttributes = [];

    /**
     * @var bool When true, the MODIFY cannot be applied via an in-place ALTER and is executed as
     * DROP COLUMN + ADD COLUMN + data backfill. Currently set for VECTOR columns whose dimensions
     * or sqlType change — MariaDB cannot resize a VECTOR column in place, and the existing values
     * are no longer dimensionally compatible.
     */
    public bool $requiresFullReset = false;

    /**
     * @var string|null Optional UPDATE statement run after the column is in its target shape, to
     * fill rows with a sensible default value. Currently used for VECTOR columns (zero vector of
     * the target dimensionality). Executed in the data-backfill phase after all schema changes.
     */
    public ?string $resetSql = null;

    /** @var string Single ALTER TABLE statement that applies this column diff. */
    public string $sql = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->changeKind . '_' . $this->columnName);
    }
}

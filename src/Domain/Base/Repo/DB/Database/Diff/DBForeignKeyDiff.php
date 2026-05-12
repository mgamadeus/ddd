<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;

/**
 * Describes a single foreign-key-level delta. FKs are matched between target and current by
 * (internalIdColumn, foreignTable, foreignIdColumn) — the constraint name is auto-generated and
 * therefore unstable.
 */
class DBForeignKeyDiff extends ValueObject
{
    public const string CHANGE_KIND_ADD = 'ADD';

    public const string CHANGE_KIND_DROP = 'DROP';

    public const string CHANGE_KIND_MODIFY = 'MODIFY';

    /** @var string Synthetic match key (e.g. "businessId->Businesses.id"). */
    public string $matchKey;

    /** @var string One of CHANGE_KIND_*. */
    public string $changeKind;

    /** @var string|null Live-DB constraint name (needed for DROP / MODIFY drop step). Null for pure ADD. */
    public ?string $currentConstraintName = null;

    /**
     * @var DatabaseForeignKey|null Target-side definition. Null for DROP. See
     * DBColumnDiff::$targetColumn for why this is excluded from both JSON output and OpenAPI schema.
     */
    #[HideProperty]
    #[Ignore]
    public ?DatabaseForeignKey $targetForeignKey = null;

    /** @var DBCanonicalForeignKey|null Canonical snapshot of the live FK. Null for ADD. */
    public ?DBCanonicalForeignKey $currentDefinition = null;

    /**
     * @var string[] For MODIFY: which canonical attributes differ (e.g. ['onDeleteAction']).
     */
    public array $changedAttributes = [];

    /**
     * @var string Single ALTER TABLE … DROP/ADD CONSTRAINT statement, or DROP+ADD pair for MODIFY.
     */
    public string $sql = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->changeKind . '_' . $this->matchKey);
    }
}

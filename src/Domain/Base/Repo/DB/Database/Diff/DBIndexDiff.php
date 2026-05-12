<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;

/**
 * Describes a single index-level delta. Indexes are matched between target and current by
 * (indexType, indexColumns) — the auto-generated index name is unstable, so name-based matching
 * would produce spurious diffs after framework version bumps.
 */
class DBIndexDiff extends ValueObject
{
    public const string CHANGE_KIND_ADD = 'ADD';

    public const string CHANGE_KIND_DROP = 'DROP';

    public const string CHANGE_KIND_MODIFY = 'MODIFY';

    /**
     * @var string Synthetic key built from indexType + indexColumns for set-diff lookups. Not used
     * in SQL — actual SQL emits the canonical name (target side) or the live name (drop side).
     */
    public string $matchKey;

    /** @var string One of CHANGE_KIND_*. */
    public string $changeKind;

    /** @var string|null Live-DB index name (used for DROP / MODIFY drop step). Null for pure ADD. */
    public ?string $currentIndexName = null;

    /**
     * @var DatabaseIndex|null Target-side definition. Null for DROP. See DBColumnDiff::$targetColumn
     * for why this is excluded from both JSON output and OpenAPI schema.
     */
    #[HideProperty]
    #[Ignore]
    public ?DatabaseIndex $targetIndex = null;

    /** @var DBCanonicalIndex|null Canonical snapshot of the live index. Null for ADD. */
    public ?DBCanonicalIndex $currentDefinition = null;

    /**
     * @var string[] For MODIFY: canonical attributes that differ (indexType, indexColumns).
     */
    public array $changedAttributes = [];

    /**
     * @var string Single statement (ADD) or DROP+ADD pair joined by ";\n" (MODIFY) or DROP (DROP).
     * MySQL cannot modify most index properties in place, so MODIFY is always implemented as
     * DROP followed by CREATE.
     */
    public string $sql = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->changeKind . '_' . $this->matchKey);
    }
}

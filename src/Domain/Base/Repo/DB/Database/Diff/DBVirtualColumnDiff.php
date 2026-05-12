<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\Canonical\DBCanonicalColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;

/**
 * Describes a single virtual-column-level delta. Virtual columns are tracked as a distinct aspect
 * from regular columns because their generation expression requires its own canonicalisation
 * before comparison.
 */
class DBVirtualColumnDiff extends ValueObject
{
    public const string CHANGE_KIND_ADD = 'ADD';

    public const string CHANGE_KIND_DROP = 'DROP';

    public const string CHANGE_KIND_MODIFY = 'MODIFY';

    /** @var string Virtual column name (e.g. "virtualTableNumber"). */
    public string $columnName;

    /** @var string One of CHANGE_KIND_*. */
    public string $changeKind;

    /**
     * @var DatabaseVirtualColumn|null Target-side definition. Null for DROP. See
     * DBColumnDiff::$targetColumn for why this is excluded from both JSON output and OpenAPI schema.
     */
    #[HideProperty]
    #[Ignore]
    public ?DatabaseVirtualColumn $targetVirtualColumn = null;

    /**
     * @var DBCanonicalColumn|null Canonical snapshot of the live virtual column. Null for ADD.
     * `isGenerated` will be true on this snapshot.
     */
    public ?DBCanonicalColumn $currentDefinition = null;

    /**
     * @var string[] For MODIFY: which canonical attributes differ
     * (e.g. ['generationExpression', 'isStored']).
     */
    public array $changedAttributes = [];

    /**
     * @var string ADD COLUMN … GENERATED ALWAYS AS … / DROP COLUMN. MODIFY is DROP + ADD because
     * MySQL cannot change a generation expression in place.
     */
    public string $sql = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->changeKind . '_' . $this->columnName);
    }
}

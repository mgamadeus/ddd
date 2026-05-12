<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Canonical snapshot of a single index. Indexes are matched between live and target by
 * (indexType, indexColumns) — the auto-generated index name is unstable, so name-based matching
 * would falsely report diffs after naming-convention changes.
 *
 * `indexName` is populated only on the live side (it's the actual name MySQL stores). On the
 * target side it stays null because DDD generates the name deterministically at SQL-render time.
 */
class DBCanonicalIndex extends ValueObject
{
    /** @var string Synthetic key built from (indexType, indexColumns). Stable across both sides. */
    public string $matchKey;

    /** @var string One of DatabaseIndex::TYPE_* (INDEX, UNIQUE INDEX, FULLTEXT INDEX, …). */
    public string $indexType;

    /** @var string[] Index columns in declaration order. */
    public array $indexColumns = [];

    /** @var string|null Live-side index name. Null on target side. */
    public ?string $indexName = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->matchKey);
    }
}

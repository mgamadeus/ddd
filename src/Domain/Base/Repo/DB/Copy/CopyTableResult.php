<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Copy;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One table's outcome of a {@see CopyToPostgresService} phase. Every phase fills only the fields it measures:
 * `schema` / `finalise` set $seconds, `data` sets $rows + $seconds (and $absentInSource for an entity table the
 * source never had), `verify` sets $rows + $idSetMatches + $rowMismatches + $identityOk.
 */
class CopyTableResult extends ValueObject
{
    /**
     * @param string $table Target table name.
     * @param float|null $seconds Wall-clock time the phase spent on the table.
     * @param int|null $rows Rows copied (`data`) or source rows compared (`verify`).
     * @param bool|null $idSetMatches `verify`: source and target hold exactly the same id set.
     * @param bool|null $identityOk `verify`: the target identity's next value covers both sides' max id.
     * @param string[] $rowMismatches `verify`: one "id=<id>: <column>,<column>" entry per row whose canonical values differ.
     * @param bool|null $absentInSource `data`: the source database has no such table, nothing was copied.
     */
    public function __construct(
        public readonly string $table,
        public readonly ?float $seconds = null,
        public readonly ?int $rows = null,
        public readonly ?bool $idSetMatches = null,
        public readonly ?bool $identityOk = null,
        public readonly array $rowMismatches = [],
        public readonly ?bool $absentInSource = null,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->table);
    }
}

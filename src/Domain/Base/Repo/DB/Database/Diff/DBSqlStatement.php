<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One executable SQL statement produced by the diff service. Wrapped as a ValueObject so the
 * phase-ordered list on {@see DBTableDiff::$sqlStatements} is a typed {@see DBSqlStatements}
 * ObjectSet rather than a raw `string[]` — keeps the wire shape introspectable and lets future
 * helpers attach per-statement metadata (phase index, intent, dependency hints) without breaking
 * existing callers.
 */
class DBSqlStatement extends ValueObject
{
    /** @var string The SQL statement as it is sent to the database (terminating `;` omitted). */
    public string $sql;

    /**
     * Statement order is meaningful (DROP→ALTER→ADD→BACKFILL→CREATE) and identical statements
     * may legitimately appear multiple times in a single diff (e.g. repeated `DROP INDEX` ops on
     * separate columns of the same name in different phases). Keying off the SQL text alone would
     * let `ObjectSet::add()` silently drop duplicates and corrupt insertion order. Use the PHP
     * object identity instead so every constructed instance survives the set's `contains()` gate.
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}

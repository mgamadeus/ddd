<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Ordered set of {@see DBSqlStatement}. Ordering is meaningful — the phase-ordered assembler in
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::assemblePhaseOrderedStatements()}
 * emits in DROP→ALTER→ADD→BACKFILL→CREATE order; execution must preserve insertion order or the
 * DDL safety invariants break (FK refs, index dependencies, trigger-vs-column ordering).
 *
 * @property DBSqlStatement[] $elements
 * @method DBSqlStatement first()
 * @method DBSqlStatement[] getElements()
 */
class DBSqlStatements extends ObjectSet
{
    /**
     * Convenience for callers that just want the string forms in order — primarily the executor
     * (which feeds Doctrine's `executeStatement`) and the signature canonicaliser (which sorts /
     * normalises whitespace for hashing).
     *
     * @return string[]
     */
    public function toStringList(): array
    {
        $out = [];
        foreach ($this->elements as $stmt) {
            $out[] = $stmt->sql;
        }
        return $out;
    }

    /**
     * Builds a set from a list of raw SQL strings. Empty strings are skipped — they're never
     * meaningful as standalone statements and would crash the prepare/execute path.
     *
     * @param string[] $strings
     */
    public static function fromStringList(array $strings): self
    {
        $set = new self();
        foreach ($strings as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            $stmt = new DBSqlStatement();
            $stmt->sql = $sql;
            $set->add($stmt);
        }
        return $set;
    }
}

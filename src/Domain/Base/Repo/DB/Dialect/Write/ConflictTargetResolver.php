<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Resolves the `ON CONFLICT` arbiter for one row (dual-dialect architecture plan §4.6, Mig §3.3 "Conflict target,
 * inferred"). Full rule per Mig §3.3: (1) id set → PK; (2) one UNIQUE key left after dropping supersets → that
 * key; (3) several → the single key whose columns are all set on this row; (4) still ambiguous →
 * `#[UpsertConflictKey([...])]`.
 *
 * **Implemented here: (1)-(3).** (4) (the attribute) and the nullable-key runtime fallback (`INSERT … ON CONFLICT
 * DO NOTHING RETURNING id` + per-key lookup in MariaDB's index-creation order) are not built — no fixture entity
 * needs them (every UNIQUE key on the three harness fixtures, `CounterRow::$businessKey` incl., is a single
 * NOT-NULL-backed column, so (3) never has more than one candidate). An entity that DOES need (4) throws below
 * instead of silently picking the wrong arbiter.
 */
final class ConflictTargetResolver
{
    /**
     * @param string[] $presentColumnNames The row's own (non-identifier) column names — a candidate UNIQUE key
     *   qualifies only when every one of its columns is among them.
     * @return string[] Unquoted column names of the resolved arbiter; `[]` when none applies (plain INSERT).
     */
    public static function resolve(DatabaseModel $databaseModel, array $presentColumnNames, bool $hasId): array
    {
        if ($hasId) {
            $primaryKeyColumn = $databaseModel->columns->getPrimaryKeyColumn();
            return $primaryKeyColumn !== null ? [$primaryKeyColumn->name] : [];
        }

        $candidates = [];
        foreach ($databaseModel->indexes->getElements() as $index) {
            if ($index->indexType !== DatabaseIndex::TYPE_UNIQUE || $index->indexColumns === []) {
                continue;
            }
            if (array_diff($index->indexColumns, $presentColumnNames) === []) {
                $candidates[] = $index->indexColumns;
            }
        }
        if ($candidates === []) {
            return [];
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        throw new InternalErrorException(
            'ConflictTargetResolver: "' . $databaseModel->sqlTableName . '" has ' . count($candidates)
            . ' UNIQUE keys whose columns are all set on this row — ambiguous arbiter. Mig §3.3\'s '
            . '#[UpsertConflictKey([...])] disambiguation attribute is not implemented; declare one fewer '
            . 'simultaneously-set UNIQUE key on this write, or extend this resolver.'
        );
    }
}

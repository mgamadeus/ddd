<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * Pure grouping logic shared by {@see PostgresUpsertBuilder::upsertMultiple()} (dual-dialect architecture plan
 * §4.6 "BatchPlanner"): contiguous runs of same-shape rows, in input order — the same grouping
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager::buildUpsertMultipleStatements()} already does
 * for MariaDB's ODKU batches. The one thing MariaDB never needs and PostgreSQL must (Mig §3.3 "Repeated keys in
 * one batch"): `ON CONFLICT` rejects a target row touched twice by the SAME statement (21000, "ON CONFLICT DO
 * UPDATE command cannot affect row a second time") — a repeated conflict-key value within a run marks it
 * `rowByRow`, so the caller executes that run's models one statement at a time, in input order, instead of one
 * multi-row INSERT.
 */
final class BatchPlanner
{
    /**
     * @param array<int, array{fieldNames: string[], columnsToReplace: string[]}> $rowShapes Keyed by the
     *   original model index (0-based, contiguous) — the shape (initialized field names + which of them replace
     *   instead of merge) that decides a run boundary.
     * @param callable(int): (string|null) $conflictKeyForIndex Returns a stable string identifying the row's
     *   conflict-target VALUE (e.g. `"id=5"` or the key column values joined), or null when the row has no
     *   resolvable target (nothing to collide on).
     * @return array<int, array{modelIndexes: int[], rowByRow: bool}> Runs in input order.
     */
    public static function planRuns(array $rowShapes, callable $conflictKeyForIndex): array
    {
        $runs = [];
        $currentKey = null;
        foreach ($rowShapes as $index => $shape) {
            $key = implode(',', $shape['fieldNames']) . '|' . implode(',', $shape['columnsToReplace']);
            if ($key !== $currentKey || $runs === []) {
                $runs[] = ['modelIndexes' => [], 'rowByRow' => false];
                $currentKey = $key;
            }
            $runs[array_key_last($runs)]['modelIndexes'][] = $index;
        }

        foreach ($runs as &$run) {
            $seenConflictKeys = [];
            foreach ($run['modelIndexes'] as $index) {
                $conflictKey = $conflictKeyForIndex($index);
                if ($conflictKey === null) {
                    continue;
                }
                if (isset($seenConflictKeys[$conflictKey])) {
                    $run['rowByRow'] = true;
                    break;
                }
                $seenConflictKeys[$conflictKey] = true;
            }
        }
        unset($run);

        return $runs;
    }
}

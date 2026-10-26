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
class BatchPlanner
{
    /**
     * @param BatchRowShapes $rowShapes One per row, in input order — the shape (initialized field names + which of
     *   them replace instead of merge) that decides a run boundary. A row's position is its model index.
     * @param callable(int): (string|null) $conflictKeyForIndex Returns a stable string identifying the row's
     *   conflict-target VALUE (e.g. `"id=5"` or the key column values joined), or null when the row has no
     *   resolvable target (nothing to collide on).
     * @return BatchRuns Runs in input order.
     */
    public static function planRuns(BatchRowShapes $rowShapes, callable $conflictKeyForIndex): BatchRuns
    {
        $runs = new BatchRuns();
        $currentRun = null;
        $currentKey = null;
        foreach ($rowShapes->getElements() as $index => $shape) {
            $key = $shape->getShapeKey();
            if ($key !== $currentKey || $currentRun === null) {
                $currentRun = new BatchRun();
                $runs->add($currentRun);
                $currentKey = $key;
            }
            $currentRun->modelIndexes[] = $index;
        }

        foreach ($runs->getElements() as $run) {
            $seenConflictKeys = [];
            foreach ($run->modelIndexes as $index) {
                $conflictKey = $conflictKeyForIndex($index);
                if ($conflictKey === null) {
                    continue;
                }
                if (isset($seenConflictKeys[$conflictKey])) {
                    $run->rowByRow = true;
                    break;
                }
                $seenConflictKeys[$conflictKey] = true;
            }
        }

        return $runs;
    }
}

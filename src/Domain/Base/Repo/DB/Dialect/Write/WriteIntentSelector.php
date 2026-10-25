<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * Selects a {@see WriteIntent} (dual-dialect architecture plan §4.6 "Selection"). `updateColumnsById()` and
 * `insertIgnore` are decided by their call site before this ever runs (they never reach the generic upsert path);
 * this covers the remaining three: id present, a resolvable unique key, or neither.
 */
final class WriteIntentSelector
{
    public static function select(bool $hasId, array $conflictTargetColumns): WriteIntent
    {
        if ($hasId) {
            return WriteIntent::UPDATE_BY_ID;
        }
        if ($conflictTargetColumns !== []) {
            return WriteIntent::UPSERT_BY_KEY;
        }
        return WriteIntent::INSERT;
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * Per-column ON CONFLICT / UPDATE form (dual-dialect architecture plan §4.6 "UpdatePolicy" table). Selection
 * mirrors {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager::upsert()}'s own per-column branching
 * (the MariaDB ODKU builder this replaces on PostgreSQL), so the same column attribute reads the same intent on
 * both engines:
 *
 * - {@see UpdatePolicy::MERGE_PATCH}: `#[DatabaseColumn(isMergableJSONColumn: true)]` columns whose value is an
 *   array/object, unless the field is in the model's `columnsToReplaceInsteadOfMerge` (then {@see self::REPLACE}).
 * - {@see UpdatePolicy::KEEP_CREATED}: the entity's ChangeHistory `created` column — never overwritten once set.
 * - {@see UpdatePolicy::RAW_ACTION}: a `#[DatabaseColumn(onUpdateAction: '...')]` column. The attribute's value is
 *   MariaDB SQL written against `VALUES(`col`)`; {@see \DDD\Domain\Base\Repo\DB\Dialect\Write\PostgresUpsertBuilder}
 *   translates it generically (`VALUES(`col`)` → `EXCLUDED."col"`, remaining backtick identifiers → double-quoted)
 *   rather than parsing per-shape (INCREMENT/KEEP_EXISTING/EXPRESSION, §4.6's three named forms) — the two fixture
 *   shapes (`CounterRow::$counter`'s `` `counter` + COALESCE(VALUES(`counter`), 1) `` and `$keptValue`'s bare
 *   `` `keptValue` ``) both round-trip correctly through that one translation, and it generalises to any future
 *   `onUpdateAction` without a new enum case per shape.
 * - {@see UpdatePolicy::REPLACE}: everything else (the default).
 */
enum UpdatePolicy
{
    case REPLACE;
    case KEEP_CREATED;
    case MERGE_PATCH;
    case RAW_ACTION;
}

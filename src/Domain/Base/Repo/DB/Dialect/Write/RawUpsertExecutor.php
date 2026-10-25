<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Backs {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::upsert()} /
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::insertOrNull()} (dual-dialect architecture plan §4.3): a raw
 * `UpsertSpec` (table name, {@see ColumnWrite} list, conflict target) for app code that writes its own `INSERT …
 * ON DUPLICATE KEY UPDATE` today and has no full DDD entity to route through
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder} (which is keyed on a `DoctrineModel`, not a bare
 * table/column map).
 *
 * Deliberately NOT the same class as {@see MariaDbUpsertBuilder}/{@see PostgresUpsertBuilder} (both `final`, both
 * keyed on `DoctrineModel`) — this is the app-facing raw-SQL sibling, sharing only the dialect-neutral vocabulary
 * ({@see UpsertSpec}, {@see ColumnWrite}, {@see UpdatePolicy}, {@see BindExpressionKind}). The `RAW_ACTION`
 * translation below mirrors {@see PostgresUpsertBuilder::renderRawActionForConflictForm()} byte-for-byte (same
 * `VALUES(`col`)` → `EXCLUDED."col"`, remaining backticks → `t."col"` rule, verified against the same 42702
 * ambiguity the import'd docblock there explains) rather than diverging on a second implementation of it.
 */
final class RawUpsertExecutor
{
    public static function upsertMariaDb(Connection $connection, UpsertSpec $spec): UpsertResult
    {
        [$sql, $values, $types] = self::renderMariaDb($spec);
        $connection->executeStatement($sql, $values, $types);
        $id = $spec->idValue ?? (int)$connection->lastInsertId();
        // ROW_COUNT() is 1 for a fresh INSERT, 2 for a row ODKU actually changed, 0 when ODKU found the row
        // already equal (no-op update) — the last case answers "not a fresh insert" correctly even though it
        // is not literally 1, so this checks "not an update outcome" via the >= 2 (changed) / 0 (no-op) split:
        // anything other than exactly 1 means the conflict branch ran.
        $rowCount = (int)$connection->fetchOne('SELECT ROW_COUNT()');
        return new UpsertResult($id, $rowCount === 1);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public static function insertOrNullMariaDb(Connection $connection, UpsertSpec $spec): ?int
    {
        [$sql, $values, $types] = self::renderMariaDb($spec, forInsertOrNull: true);
        try {
            $connection->executeStatement($sql, $values, $types);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        return $spec->idValue !== null ? (int)$spec->idValue : (int)$connection->lastInsertId();
    }

    public static function upsertPostgres(Connection $connection, UpsertSpec $spec): UpsertResult
    {
        [$sql, $values, $types] = self::renderPostgres($spec);
        // §4.9 scratch-verified against a real PG 18 container: a fresh INSERT's returned row has xmax = 0; the
        // conflict branch's DO UPDATE replaces the tuple with a new version whose xmax is the CURRENT
        // transaction's id, not 0 — `xmax = 0` reliably tells the two apart within the SAME statement.
        $row = $connection->fetchAssociative($sql, $values, $types);
        if ($spec->hasId()) {
            // nextval() is not transactional (§4.6) — safe unconditionally, whichever branch (insert/update) ran.
            PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $spec->table, (int)$spec->idValue);
        }
        return new UpsertResult($row['id'], (bool)$row['inserted']);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public static function insertOrNullPostgres(Connection $connection, UpsertSpec $spec): ?int
    {
        [$sql, $values, $types] = self::renderPostgres($spec, forInsertOrNull: true);
        try {
            $row = $connection->fetchAssociative($sql, $values, $types);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        if ($spec->hasId()) {
            PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $spec->table, (int)$spec->idValue);
        }
        return (int)$row['id'];
    }

    /** @return array{0: string, 1: array, 2: string[]} */
    protected static function renderMariaDb(UpsertSpec $spec, bool $forInsertOrNull = false): array
    {
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = [];
        if ($spec->hasId()) {
            $columns[] = '`id`';
            $placeholders[] = '?';
            $values[] = $spec->idValue;
            $types[] = 'integer';
        }
        foreach ($spec->columns as $columnWrite) {
            $columns[] = '`' . $columnWrite->columnName . '`';
            $placeholders[] = self::bindExpressionMariaDb($columnWrite);
            $values[] = $columnWrite->value;
            $types[] = $columnWrite->dbalType;
        }

        $sql = 'INSERT INTO `' . $spec->table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        if ($forInsertOrNull || $spec->columns === []) {
            return [$sql, $values, $types];
        }

        $assignments = [];
        foreach ($spec->columns as $columnWrite) {
            if (in_array($columnWrite->columnName, $spec->conflictTargetColumns, true)) {
                continue;
            }
            $column = '`' . $columnWrite->columnName . '`';
            $assignments[] = match ($columnWrite->updatePolicy) {
                UpdatePolicy::RAW_ACTION => "$column = " . $columnWrite->rawOnUpdateAction,
                UpdatePolicy::KEEP_CREATED => "$column = COALESCE(VALUES($column), $column)",
                UpdatePolicy::MERGE_PATCH => "$column = JSON_MERGE_PATCH(COALESCE($column,'{}'), VALUES($column))",
                default => "$column = VALUES($column)",
            };
        }
        if ($assignments === []) {
            // every column is part of the conflict target (nothing to update) — keep MariaDB's ODKU no-op form
            $assignments[] = '`id` = `id`';
        }
        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        return [$sql, $values, $types];
    }

    protected static function bindExpressionMariaDb(ColumnWrite $columnWrite): string
    {
        return match ($columnWrite->kind) {
            BindExpressionKind::GEOMETRY => 'ST_GeomFromText(?, 0)',
            BindExpressionKind::VECTOR => 'VEC_FromText(?)',
            BindExpressionKind::PLAIN => '?',
        };
    }

    /** @return array{0: string, 1: array, 2: string[]} */
    protected static function renderPostgres(UpsertSpec $spec, bool $forInsertOrNull = false): array
    {
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = [];
        if ($spec->hasId()) {
            $columns[] = '"id"';
            $placeholders[] = '?';
            $values[] = $spec->idValue;
            $types[] = 'integer';
        }
        foreach ($spec->columns as $columnWrite) {
            $columns[] = '"' . $columnWrite->columnName . '"';
            $placeholders[] = self::bindExpressionPostgres($columnWrite);
            $values[] = $columnWrite->value;
            $types[] = $columnWrite->dbalType;
        }

        $sql = 'INSERT INTO "' . $spec->table . '" AS t (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        if ($forInsertOrNull || $spec->conflictTargetColumns === []) {
            return [$sql . ' RETURNING "id"', $values, $types];
        }

        $conflictTarget = implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', $spec->conflictTargetColumns));
        $assignments = [];
        foreach ($spec->columns as $columnWrite) {
            if (in_array($columnWrite->columnName, $spec->conflictTargetColumns, true)) {
                continue;
            }
            $assignments[] = self::conflictAssignmentPostgres($columnWrite);
        }
        if ($assignments === []) {
            $sql .= " ON CONFLICT ($conflictTarget) DO NOTHING";
        } else {
            $sql .= " ON CONFLICT ($conflictTarget) DO UPDATE SET " . implode(', ', $assignments);
        }
        $sql .= ' RETURNING "id", (xmax = 0) AS inserted';

        return [$sql, $values, $types];
    }

    protected static function bindExpressionPostgres(ColumnWrite $columnWrite): string
    {
        return match ($columnWrite->kind) {
            BindExpressionKind::GEOMETRY => 'ST_GeomFromText(?, 0)',
            BindExpressionKind::VECTOR, BindExpressionKind::PLAIN => '?',
        };
    }

    protected static function conflictAssignmentPostgres(ColumnWrite $columnWrite): string
    {
        $column = '"' . $columnWrite->columnName . '"';
        return match ($columnWrite->updatePolicy) {
            UpdatePolicy::RAW_ACTION => self::translateRawActionForConflictForm($columnWrite),
            UpdatePolicy::MERGE_PATCH => "$column = ddd_json_merge_patch(COALESCE(t.$column,'{}'), COALESCE(EXCLUDED.$column,'{}'))",
            UpdatePolicy::KEEP_CREATED => "$column = COALESCE(EXCLUDED.$column, t.$column)",
            default => "$column = EXCLUDED.$column",
        };
    }

    /**
     * Mirrors {@see PostgresUpsertBuilder::renderRawActionForConflictForm()}: `VALUES(`col`)` → `EXCLUDED."col"`,
     * remaining backtick identifiers → `t."col"` (the target alias — bare would be ambiguous against `EXCLUDED`,
     * 42702, verified against a real PG 18 container by that method's own docblock).
     */
    protected static function translateRawActionForConflictForm(ColumnWrite $columnWrite): string
    {
        $translated = preg_replace(
            '/VALUES\s*\(\s*`' . preg_quote($columnWrite->columnName, '/') . '`\s*\)/i',
            'EXCLUDED."' . $columnWrite->columnName . '"',
            (string)$columnWrite->rawOnUpdateAction
        );
        $translated = preg_replace('/`([A-Za-z0-9_]+)`/', 't."$1"', $translated);
        return '"' . $columnWrite->columnName . '" = ' . $translated;
    }
}

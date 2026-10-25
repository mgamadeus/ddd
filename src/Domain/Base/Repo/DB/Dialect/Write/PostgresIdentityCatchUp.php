<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\Connection;

/**
 * Advances a PostgreSQL identity sequence past an explicitly-inserted id, via `nextval()` only — never
 * `setval()`, which the dual-dialect architecture plan reserves for the copy tool running with writers stopped
 * (§4.6: "identity catch-up only with `nextval()` … `setval` only in the copy tool with writers stopped").
 * Without this, a later write that lets the sequence generate an id (no explicit id given) could eventually
 * reach and collide with an id a caller inserted explicitly — Mig §4.6's `UPDATE_BY_ID` "absent row → INSERT
 * with the explicit id + identity catch-up" row, and every entity upsert / `RawUpsertExecutor::upsertPostgres()`
 * / `insertOrNullPostgres()` write that supplies an id.
 *
 * **Eager only.** The plan also describes a LAZY path for a sequence that fell behind BEFORE this code ever ran
 * (a generated id's PK `23505` → `ROLLBACK TO` → an advisory transaction lock keyed by the sequence's oid →
 * `nextval()` past `max(id)` → retry once). That lazy retry is deliberately NOT built here — no fixture or
 * caller needs it: an entity written exclusively through this eager path never falls behind in the first place,
 * and a database seeded any other way (the copy tool) catches its sequences up with `setval()` before writers
 * reconnect, which is a different code path entirely.
 *
 * `nextval()` is not transactional on PostgreSQL — a rolled-back write does not un-advance the sequence — so
 * this runs unconditionally right after the write succeeds, with no savepoint/transaction-boundary coordination.
 */
final class PostgresIdentityCatchUp
{
    /**
     * Mig §3.3/§4.6: "explicit ids above `last_value + 10 000` refused outside the copy tool and imports" — a
     * gap wider than this is almost certainly a different bug (a wrong id, a cross-database copy-paste) rather
     * than a legitimate import, and driving the sequence forward thousands of times in one statement is where
     * "eager" stops being free.
     */
    protected const int MAX_EAGER_GAP = 10_000;

    /**
     * @param string $table The entity's own (unquoted) table name — always `DoctrineModel::getTableName()`,
     *   never a caller-supplied value.
     */
    public static function afterExplicitIdInsert(Connection $connection, string $table, int $insertedId): void
    {
        $sequenceName = self::resolveIdSequence($connection, $table);
        if ($sequenceName === null) {
            // No identity sequence on this table's "id" column — a defensive no-op. Every DDD entity table has
            // one in practice (`ORM\GeneratedValue` on `id`); nothing in the current fixtures takes this branch.
            return;
        }
        self::advancePast($connection, $sequenceName, $insertedId);
    }

    /**
     * `pg_get_serial_sequence()` parses its first argument with the SAME rules as the SQL parser: an unquoted
     * name is folded to lower case. DDD tables are PascalCase and quoted everywhere on PostgreSQL ({@see
     * \DDD\Domain\Base\Repo\DB\Dialect\Identifiers\AlwaysQuoteStrategy}), so the table name passed here MUST
     * carry its own double quotes too, or a mixed-case table silently resolves to nothing (the function returns
     * NULL rather than erroring — exactly why this is checked explicitly instead of trusted).
     */
    public static function resolveIdSequence(Connection $connection, string $table): ?string
    {
        $quotedTable = '"' . str_replace('"', '""', $table) . '"';
        $sequenceName = $connection->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$quotedTable, 'id']);
        return ($sequenceName !== false && $sequenceName !== null) ? (string)$sequenceName : null;
    }

    /**
     * Advances `$sequenceName` with `nextval()` calls — via `generate_series`, one round trip regardless of the
     * gap size — until its last issued value is `>= $targetId`. A target already behind or at the sequence's
     * current position is a no-op: by far the common case, since most writes supply no id at all.
     *
     * Assumes `increment 1` (every DDD identity column is a plain `GeneratedValue` sequence; the generator never
     * configures another step), rather than reading `pg_sequence_parameters()` on every single explicit-id
     * write for a property no DDD entity varies.
     */
    protected static function advancePast(Connection $connection, string $sequenceName, int $targetId): void
    {
        $state = $connection->fetchAssociative('SELECT last_value, is_called FROM ' . $sequenceName);
        if ($state === false) {
            return;
        }
        $lastValue = (int)$state['last_value'];
        $isCalled = (bool)$state['is_called'];
        $nextWouldBe = $isCalled ? $lastValue + 1 : $lastValue;
        if ($targetId < $nextWouldBe) {
            return;
        }

        $callsNeeded = $targetId - $nextWouldBe + 1;
        if ($callsNeeded > self::MAX_EAGER_GAP) {
            throw new InternalErrorException(
                'PostgresIdentityCatchUp: explicit id ' . $targetId . ' is ' . $callsNeeded . ' call(s) ahead of '
                . $sequenceName . ' — beyond the ' . self::MAX_EAGER_GAP . '-call eager catch-up bound (Mig '
                . '§3.3/§4.6); only the copy tool (writers stopped, setval()) is allowed a gap this wide.'
            );
        }

        $connection->executeStatement(
            'SELECT nextval(?::regclass) FROM generate_series(1, ?)',
            [$sequenceName, $callsNeeded],
            ['string', 'integer']
        );
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Copy;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * One COPY-text escaper and one set of per-type canonicalisation rules, shared by the data phase (MariaDB ->
 * PostgreSQL COPY) and the verify phase (comparing a canonical reading of the same row on both engines) of
 * `app:db:copy-to-postgres` (`agentic-plans/backend/2026-10-22-postgres-migration.md` §5.1/§5.5).
 *
 * The design is deliberately "one logical text value per field, escaped once, uniformly": every type-specific
 * rule below (bytea as `\x<hex>`, geometry as hex WKB, vectors as a `[…]` float list, …) produces an ordinary PHP
 * string (or null), and {@see escapeField()} is the ONLY place that touches backslash/tab/newline/CR — so a
 * `\x48656c6c6f` bytea literal and a JSON value that already contains a literal backslash+t both go through the
 * exact same escaping pass instead of two different ones that could disagree.
 */
final class CopyValueCodec
{
    /**
     * `PDO::pgsqlCopyFromArray()`'s own NULL-marker configuration — verified empirically against a real PG 18
     * server (the `postgres` driver's COPY NULL-matching requires the BACKSLASH-ESCAPED form here, 3 raw bytes
     * `\`,`\`,`N`; passing the literal 2-byte COPY NULL token `\N` instead makes the server try to parse a bare
     * "N" as column data and fail). The per-FIELD marker a row actually carries (produced by {@see escapeField()}
     * for a null value) is the ordinary 2-byte COPY NULL token, matched below.
     */
    public const string PGSQL_COPY_FROM_ARRAY_NULL_AS = "\\\\N";

    /** The 2-byte COPY TEXT NULL token a field carries in the row string itself. */
    protected const string FIELD_NULL_TOKEN = '\N';

    /**
     * Escapes one field's logical text value for COPY TEXT format: backslash, tab, newline, CR; null becomes the
     * literal NULL token. Every other canonicalisation in this class produces input for this method — never the
     * reverse.
     */
    public static function escapeField(?string $value): string
    {
        if ($value === null) {
            return self::FIELD_NULL_TOKEN;
        }
        return str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], $value);
    }

    /**
     * The MariaDB SELECT expression for one column, as read during the data phase and the verify phase. Geometry
     * columns are read through `ST_AsWKB()` (raw column reads carry an internal 4-byte SRID prefix the plain
     * WKB/EWKB-hex form must not); every other column is read plain — including VECTOR, whose raw column bytes
     * ARE the bare float32 array (no header), and BLOB, whose raw bytes are hex-encoded in PHP, not in SQL (one
     * escaper, see class docblock).
     */
    public static function mariaDbSelectExpression(DatabaseColumn $column): string
    {
        if (self::isSpatialType($column->sqlType)) {
            return "HEX(ST_AsWKB(`$column->name`)) AS `$column->name`";
        }
        return "`$column->name`";
    }

    /** The PostgreSQL SELECT expression for one column, as read during the verify phase. */
    public static function postgresSelectExpression(DatabaseColumn $column): string
    {
        if (self::isSpatialType($column->sqlType)) {
            return 'encode(ST_AsBinary("' . $column->name . '"), \'hex\') AS "' . $column->name . '"';
        }
        if (self::isBlobType($column->sqlType)) {
            // Verify compares blobs by server-side MD5 on both engines (Mig §5.5 item 2) — never the raw bytes
            // themselves through PHP, which would just be a slower, bug-prone re-implementation of the same check.
            return 'md5("' . $column->name . '") AS "' . $column->name . '"';
        }
        if ($column->sqlType === DatabaseColumn::SQL_TYPE_VECTOR) {
            return '"' . $column->name . '"::text AS "' . $column->name . '"';
        }
        return '"' . $column->name . '"';
    }

    /** The MariaDB SELECT expression for a BLOB column during verify — MD5, matching postgresSelectExpression(). */
    public static function mariaDbVerifySelectExpression(DatabaseColumn $column): string
    {
        if (self::isBlobType($column->sqlType)) {
            return "MD5(`$column->name`) AS `$column->name`";
        }
        return self::mariaDbSelectExpression($column);
    }

    /**
     * The logical (pre-escape) COPY field text for one source value read via {@see mariaDbSelectExpression()},
     * or null. Throws on a zero-date in a NOT NULL column (Mig §5.1: "zero-date guard" — the one case this command
     * refuses outright instead of writing something silently wrong).
     */
    public static function canonicalizeSourceValueForCopy(
        DatabaseColumn $column,
        mixed $value,
        string $table,
        int|string $id
    ): ?string {
        if ($value === null) {
            return null;
        }
        return match (true) {
            $column->sqlType === DatabaseColumn::SQL_TYPE_BOOL => ((int)$value !== 0) ? 't' : 'f',
            $column->sqlType === DatabaseColumn::SQL_TYPE_JSON => self::validatedJsonText((string)$value, $table, $column->name, $id),
            $column->sqlType === DatabaseColumn::SQL_TYPE_DATE => self::zeroDateGuard((string)$value, $column, $table, $id, 10),
            $column->sqlType === DatabaseColumn::SQL_TYPE_DATETIME => self::zeroDateGuard((string)$value, $column, $table, $id, 19),
            $column->sqlType === DatabaseColumn::SQL_TYPE_FLOAT => sprintf('%.9g', (float)$value),
            $column->sqlType === DatabaseColumn::SQL_TYPE_DOUBLE => sprintf('%.17g', (float)$value),
            self::isBlobType($column->sqlType) => '\\x' . bin2hex((string)$value),
            $column->sqlType === DatabaseColumn::SQL_TYPE_VECTOR => self::vectorBytesToText((string)$value),
            default => (string)$value,
        };
    }

    /**
     * The canonical comparison value of one column read via {@see mariaDbVerifySelectExpression()} /
     * {@see postgresSelectExpression()} — same rules as the copy side, minus the zero-date guard (verify never
     * writes; a zero-date already became NULL on copy, or the copy already refused it) and minus BLOB (both sides
     * already project an MD5 hash, directly comparable as plain text).
     */
    public static function canonicalizeValueForVerify(DatabaseColumn $column, mixed $value, bool $fromPostgres): ?string
    {
        if ($value === null) {
            return null;
        }
        return match (true) {
            $column->sqlType === DatabaseColumn::SQL_TYPE_BOOL => ((int)self::boolToInt($value) !== 0) ? 't' : 'f',
            // Bit patterns, not decimal text (Mig §5.5 item 2): MariaDB and PostgreSQL each print the SHORTEST
            // decimal that round-trips to their stored value, and for a FLOAT (32-bit) that shortest text, re-parsed
            // as a PHP (64-bit) float, is a different float64 value than the original float32 widened to float64 —
            // e.g. a float32 nearest to 0.1 widens to 0.10000000149…, while PostgreSQL's own "0.1" text parses back
            // to the plain float64 nearest 0.1. Comparing %.9g-formatted TEXT of those two float64s then disagrees
            // on a real, correctly-copied value. Re-packing each side to its native width before comparing removes
            // the ambiguity entirely.
            $column->sqlType === DatabaseColumn::SQL_TYPE_FLOAT => bin2hex(pack('g', (float)$value)),
            $column->sqlType === DatabaseColumn::SQL_TYPE_DOUBLE => bin2hex(pack('e', (float)$value)),
            $column->sqlType === DatabaseColumn::SQL_TYPE_VECTOR => $fromPostgres
                ? self::vectorPgTextToBitHex((string)$value)
                : self::vectorBytesToBitHex((string)$value),
            $column->sqlType === DatabaseColumn::SQL_TYPE_DATE,
            $column->sqlType === DatabaseColumn::SQL_TYPE_DATETIME => self::normalizeZeroDateToNull((string)$value),
            self::isSpatialType($column->sqlType) => strtolower((string)$value),
            default => (string)$value,
        };
    }

    protected static function boolToInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        // pdo_pgsql's boolean type can surface as PHP bool, "t"/"f", or "1"/"0" depending on attribute/driver mode.
        if (is_string($value)) {
            return in_array($value, ['t', '1', 'true'], true) ? 1 : 0;
        }
        return (int)$value !== 0 ? 1 : 0;
    }

    protected static function normalizeZeroDateToNull(string $value): ?string
    {
        return str_starts_with($value, '0000-00-00') ? null : $value;
    }

    protected static function zeroDateGuard(
        string $value,
        DatabaseColumn $column,
        string $table,
        int|string $id,
        int $expectedLength
    ): ?string {
        if (str_starts_with($value, '0000-00-00')) {
            if ($column->allowsNull !== false) {
                return null;
            }
            throw new InternalErrorException(
                "app:db:copy-to-postgres: zero-date in NOT NULL column \"$table\".\"$column->name\" (id=$id) has no "
                . 'PostgreSQL representation and the column does not allow NULL — fix the source row before copying.'
            );
        }
        return substr($value, 0, $expectedLength);
    }

    protected static function validatedJsonText(string $value, string $table, string $columnName, int|string $id): string
    {
        json_decode($value, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InternalErrorException(
                "app:db:copy-to-postgres: \"$table\".\"$columnName\" (id=$id) does not hold valid JSON "
                . "({$value}) — " . json_last_error_msg()
            );
        }
        return $value;
    }

    /** Raw float32-LE bytes (MariaDB VECTOR's on-wire column form, no header) -> PostgreSQL's `[v,v,…]` vector literal. */
    protected static function vectorBytesToText(string $rawBytes): string
    {
        $floats = self::unpackVectorFloats($rawBytes);
        return '[' . implode(',', array_map(static fn (float $f): string => sprintf('%.9g', $f), $floats)) . ']';
    }

    /** @return float[] */
    protected static function unpackVectorFloats(string $rawBytes): array
    {
        $floats = unpack('g*', $rawBytes);
        if ($floats === false) {
            throw new InternalErrorException('app:db:copy-to-postgres: could not unpack VECTOR bytes as float32.');
        }
        return array_values($floats);
    }

    /** Verify (Mig §5.5 item 2): raw float32-LE bytes, concatenated hex — the bit pattern itself, no decimal text. */
    protected static function vectorBytesToBitHex(string $rawBytes): string
    {
        return bin2hex($rawBytes);
    }

    /**
     * Verify, target side: PostgreSQL's own `vector::text` output (e.g. "[0.5,0.25]") parsed back and re-packed as
     * float32 — the value PostgreSQL printed is, by its own round-trip guarantee, the shortest decimal that parses
     * back to the EXACT stored float4 bit pattern, so re-packing it here recovers that same bit pattern (see
     * {@see canonicalizeValueForVerify()}'s docblock comment for why comparing decimal text instead does not work).
     */
    protected static function vectorPgTextToBitHex(string $pgVectorText): string
    {
        $inner = trim($pgVectorText, '[]');
        if ($inner === '') {
            return '';
        }
        $bytes = '';
        foreach (explode(',', $inner) as $part) {
            $bytes .= pack('g', (float)$part);
        }
        return bin2hex($bytes);
    }

    /** DatabaseColumn::SPATIAL_SQL_TYPES is an associative set keyed by LOWERCASE type names (point/linestring/…); $column->sqlType is the UPPERCASE SQL_TYPE_* constant — array_key_exists() on the lowered value, never in_array(). */
    protected static function isSpatialType(?string $sqlType): bool
    {
        return $sqlType !== null && array_key_exists(strtolower($sqlType), DatabaseColumn::SPATIAL_SQL_TYPES);
    }

    protected static function isBlobType(?string $sqlType): bool
    {
        return in_array(
            $sqlType,
            [DatabaseColumn::SQL_TYPE_BLOB, DatabaseColumn::SQL_TYPE_MEDIUMBLOB, DatabaseColumn::SQL_TYPE_LONGBLOB],
            true
        );
    }
}

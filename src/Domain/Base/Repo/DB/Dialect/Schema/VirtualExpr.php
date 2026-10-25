<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

/**
 * Typed intent behind a `#[DatabaseVirtualColumn(as: …)]` raw MariaDB expression (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.10, build step 7). No entity ever
 * constructs this directly: {@see fromMariaDbExpression()} is a **strict recogniser** that maps today's raw MariaDB
 * `as:` strings to one of four intents by shape; the MariaDB string stays the single source of truth (rendered back
 * byte-identical by {@see renderMariaDb()}, so the golden suite never moves a byte), while {@see renderPostgres()}
 * produces the equivalent PostgreSQL generated-column expression.
 *
 * Covers exactly the shapes Dineful/Radbonus use today (Mig §3.7): `IFNULL(col, 0)` / `IFNULL(col, 'literal')`
 * (intent `coalesce`), and the three `JSON_UNQUOTE(JSON_EXTRACT(col, '$.key'))` forms — plain, `CAST(… AS SIGNED)`,
 * `CAST(… AS DECIMAL(p,s))` (intents `jsonText` / `jsonInt` / `jsonDecimal`). Anything else is not recognised —
 * {@see fromMariaDbExpression()} returns null and the caller ({@see PostgresDdlRenderer}) refuses with a message
 * naming the entity, property and expression, rather than guessing.
 */
final readonly class VirtualExpr
{
    public const string INTENT_COALESCE = 'coalesce';

    public const string INTENT_JSON_TEXT = 'jsonText';

    public const string INTENT_JSON_INT = 'jsonInt';

    public const string INTENT_JSON_DECIMAL = 'jsonDecimal';

    private function __construct(
        public string $intent,
        /** The original, unmodified `as:` string — {@see renderMariaDb()} returns this verbatim. */
        public string $mariaDbExpression,
        /** The column referenced inside the expression (the IFNULL argument, or the JSON source column). */
        public string $column,
        /** `coalesce` only: the literal MariaDB coalesces to, already typed (int 0, or a string). */
        public int|string|null $literal = null,
        /** `jsonText` / `jsonInt` / `jsonDecimal` only: the `$.key` path segment. */
        public ?string $jsonKey = null,
        /** `jsonDecimal` only. */
        public ?int $precision = null,
        public ?int $scale = null,
    ) {
    }

    public static function coalesce(string $mariaDbExpression, string $column, int|string $literal): self
    {
        return new self(self::INTENT_COALESCE, $mariaDbExpression, $column, literal: $literal);
    }

    public static function jsonText(string $mariaDbExpression, string $column, string $jsonKey): self
    {
        return new self(self::INTENT_JSON_TEXT, $mariaDbExpression, $column, jsonKey: $jsonKey);
    }

    public static function jsonInt(string $mariaDbExpression, string $column, string $jsonKey): self
    {
        return new self(self::INTENT_JSON_INT, $mariaDbExpression, $column, jsonKey: $jsonKey);
    }

    public static function jsonDecimal(string $mariaDbExpression, string $column, string $jsonKey, int $precision, int $scale): self
    {
        return new self(self::INTENT_JSON_DECIMAL, $mariaDbExpression, $column, jsonKey: $jsonKey, precision: $precision, scale: $scale);
    }

    /**
     * Strict, whitespace-tolerant recogniser for today's five raw-expression shapes (Mig §3.7). Keyword casing
     * (`IFNULL`, `JSON_UNQUOTE`, `JSON_EXTRACT`, `CAST`, `AS`, `SIGNED`, `DECIMAL`) matches exactly what every
     * existing `#[DatabaseVirtualColumn(as: …)]` call site in Dineful/Radbonus/the translations module uses. Any
     * other shape (different casing, a function not in this list, a multi-argument expression) is not recognised —
     * returns null rather than guessing a translation.
     */
    public static function fromMariaDbExpression(string $as): ?self
    {
        $identifier = '[A-Za-z_][A-Za-z0-9_]*';
        $ws = '\s*';

        // (IFNULL(col, 0))
        if (preg_match("/^\({$ws}IFNULL\({$ws}($identifier){$ws},{$ws}0{$ws}\){$ws}\)\$/", $as, $m)) {
            return self::coalesce($as, $m[1], 0);
        }
        // (IFNULL(col, 'literal')) — today's one use is '', but any single-quoted literal is the same shape.
        if (preg_match("/^\({$ws}IFNULL\({$ws}($identifier){$ws},{$ws}'((?:[^'\\\\]|\\\\.)*)'{$ws}\){$ws}\)\$/", $as, $m)) {
            return self::coalesce($as, $m[1], stripslashes($m[2]));
        }

        $jsonExtract = "JSON_UNQUOTE\({$ws}JSON_EXTRACT\({$ws}($identifier){$ws},{$ws}'\\\$\.($identifier)'{$ws}\){$ws}\)";

        // (JSON_UNQUOTE(JSON_EXTRACT(col, '$.key')))
        if (preg_match("/^\({$ws}{$jsonExtract}{$ws}\)\$/", $as, $m)) {
            return self::jsonText($as, $m[1], $m[2]);
        }
        // (CAST(JSON_UNQUOTE(JSON_EXTRACT(col, '$.key')) AS SIGNED))
        if (preg_match("/^\({$ws}CAST\({$ws}{$jsonExtract}{$ws}AS{$ws}SIGNED{$ws}\){$ws}\)\$/", $as, $m)) {
            return self::jsonInt($as, $m[1], $m[2]);
        }
        // (CAST(JSON_UNQUOTE(JSON_EXTRACT(col, '$.key')) AS DECIMAL(p,s)))
        if (preg_match("/^\({$ws}CAST\({$ws}{$jsonExtract}{$ws}AS{$ws}DECIMAL\({$ws}(\d+){$ws},{$ws}(\d+){$ws}\){$ws}\){$ws}\)\$/", $as, $m)) {
            return self::jsonDecimal($as, $m[1], $m[2], (int)$m[3], (int)$m[4]);
        }

        return null;
    }

    /** Exactly the original string — the MariaDB golden never moves a byte (Arch rule 1). */
    public function renderMariaDb(): string
    {
        return $this->mariaDbExpression;
    }

    /**
     * The PostgreSQL generated-column expression. `json` has no `->>`-on-non-json shortcut, so every JSON intent
     * casts the source column to `json` explicitly first (the source column's PostgreSQL type is already `json`,
     * Mig §3.1/§3.2 — the cast is defensive and free).
     */
    public function renderPostgres(): string
    {
        $column = '"' . $this->column . '"';

        return match ($this->intent) {
            self::INTENT_COALESCE => 'COALESCE(' . $column . ', ' . $this->literalSql() . ')',
            self::INTENT_JSON_TEXT => '(' . $column . '::json ->> ' . $this->quotedKey() . ')',
            self::INTENT_JSON_INT => '((' . $column . '::json ->> ' . $this->quotedKey() . '))::bigint',
            self::INTENT_JSON_DECIMAL => '((' . $column . '::json ->> ' . $this->quotedKey() . "))::numeric($this->precision,$this->scale)",
        };
    }

    /**
     * The PostgreSQL column type for the generated column — intent-derived for the JSON intents (the `as`
     * expression already fully determines the result type, independent of the extracted property's own PHP type);
     * `coalesce` has no type of its own and is not covered here — the caller uses the referenced column's own
     * mapped type (a passthrough of an existing column, e.g. another `bigint` id or `varchar` column).
     */
    public function postgresColumnType(): string
    {
        return match ($this->intent) {
            self::INTENT_JSON_TEXT => 'text',
            self::INTENT_JSON_INT => 'bigint',
            self::INTENT_JSON_DECIMAL => "numeric($this->precision,$this->scale)",
            self::INTENT_COALESCE => throw new \LogicException(
                'VirtualExpr::postgresColumnType() does not cover intent "coalesce" — use the referenced column\'s own mapped type.'
            ),
        };
    }

    private function literalSql(): string
    {
        if (is_int($this->literal)) {
            return (string)$this->literal;
        }
        return "'" . str_replace("'", "''", (string)$this->literal) . "'";
    }

    private function quotedKey(): string
    {
        return "'" . str_replace("'", "''", (string)$this->jsonKey) . "'";
    }
}

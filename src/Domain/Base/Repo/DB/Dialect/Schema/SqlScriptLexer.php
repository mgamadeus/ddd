<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

/**
 * Splits one SQL script (a `{Entity}{Name}Trigger.sql` / `….pgsql.sql` file, Arch §4.10) into individually
 * executable statements, the way a real SQL client does it — NOT the naive `explode(';', …)` that
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::splitMultiStatementSql()} used before a trigger with
 * a `BEGIN…END` body (MariaDB) or a `$tag$`-quoted function body (PostgreSQL) could land in this generator path —
 * every semicolon inside the body is itself a statement terminator in isolation but must NOT split the script
 * there.
 *
 * A character scanner, not a full SQL parser: it tracks exactly the constructs that can hide a `;` (or the active
 * delimiter) from being a real statement boundary:
 *   - single-quoted strings (`'…'`), with both `''` doubling and backslash escaping tolerated (MariaDB defaults to
 *     backslash-escaping; PostgreSQL's `standard_conforming_strings` default does not, but tolerating the escape
 *     is harmless when the content never actually contains a trailing backslash before a quote);
 *   - double-quoted identifiers (`"…"`), doubled-quote escaping only (no backslash — matches both engines'
 *     identifier-quoting rules);
 *   - backtick-quoted identifiers (`` `…` ``, MariaDB only), doubled-backtick escaping only;
 *   - dollar-quoted strings (`$$…$$` / `$tag$…$tag$`, PostgreSQL function bodies) — the single construct that can
 *     contain literal single quotes, so a PL/pgSQL body never needs to escape its own apostrophes;
 *   - line comments (`--` when followed by whitespace/EOL, or `#`) and block comments (`/* … *‍/`);
 *   - the `DELIMITER <token>` meta-command (a `mysql`-CLI convention, not a real SQL statement — useful when a
 *     hand-authored trigger file is written in the classic mysqldump style);
 *   - MariaDB `BEGIN…END` compound statements (a trigger/procedure/function body with NO `DELIMITER` change and NO
 *     dollar-quoting to protect it — unlike PostgreSQL, whose function body is always dollar-quoted, step 6
 *     already protects it). A depth counter increments on `BEGIN`/`CASE`/`LOOP`/`WHILE`/`REPEAT` (and on `IF` only
 *     when it is NOT a function call, i.e. not immediately followed by `(`) and decrements on `END` (optionally
 *     followed by one of the same keywords, e.g. `END IF`, consumed as a single closer) — the active delimiter is
 *     never matched while the depth is above zero. Counting generic opener/closer keywords rather than matching
 *     each pair by type is sufficient: every opener inside a real routine body is eventually closed by exactly one
 *     `END`-shaped token, so the depth returns to zero exactly when the outermost `BEGIN` (or a plain top-level
 *     `CASE … END` expression, which needs no enclosing `BEGIN` at all) closes. **Not handled:** a standalone
 *     transactional `BEGIN` statement (`BEGIN;` / `START TRANSACTION`), which this file never contains — only
 *     generated DDL and trigger source, never arbitrary app SQL, is ever passed to this class.
 *
 * Statements are returned trimmed, with blank/comment-only entries dropped; comments INSIDE a returned statement
 * are kept verbatim (harmless to execute, and stripping them is not this class's job).
 */
final class SqlScriptLexer
{
    /**
     * @return string[] Individually executable statements, in source order.
     */
    public static function splitStatements(string $script): array
    {
        $length = strlen($script);
        $delimiter = ';';
        $statements = [];
        $current = '';
        $i = 0;
        $compoundDepth = 0;

        while ($i < $length) {
            // 1. `DELIMITER <token>` meta-command — only recognised at the start of a line while no partial
            //    statement has accumulated yet (a real SQL statement never starts with this word used this way).
            if (($i === 0 || $script[$i - 1] === "\n") && trim($current) === '') {
                $delimiterCommand = self::matchDelimiterCommand($script, $i);
                if ($delimiterCommand !== null) {
                    [$newDelimiter, $consumedLength] = $delimiterCommand;
                    $delimiter = $newDelimiter;
                    $i += $consumedLength;
                    continue;
                }
            }

            // 2. The active delimiter — only a real statement boundary outside a BEGIN…END compound statement, and
            //    checked before dollar-quote detection so a MariaDB `DELIMITER $$` body (no PostgreSQL dollar-
            //    quoting involved at all) is not misread as a dollar-quote opener.
            if ($compoundDepth === 0 && self::matchesAt($script, $i, $delimiter)) {
                self::pushStatement($statements, $current);
                $current = '';
                $i += strlen($delimiter);
                continue;
            }

            $char = $script[$i];

            // 3. Line comments.
            if ($char === '#' || ($char === '-' && self::isLineCommentStart($script, $i, $length))) {
                $end = strpos($script, "\n", $i);
                $segment = $end === false ? substr($script, $i) : substr($script, $i, $end - $i + 1);
                $current .= $segment;
                $i += strlen($segment);
                continue;
            }

            // 4. Block comments.
            if ($char === '/' && $i + 1 < $length && $script[$i + 1] === '*') {
                $end = strpos($script, '*/', $i + 2);
                $segment = $end === false ? substr($script, $i) : substr($script, $i, $end - $i + 2);
                $current .= $segment;
                $i += strlen($segment);
                continue;
            }

            // 5. Quoted/escaped regions.
            if ($char === "'" || $char === '"') {
                $segment = self::consumeQuoted($script, $i, $length, $char, escapable: true);
                $current .= $segment;
                $i += strlen($segment);
                continue;
            }
            if ($char === '`') {
                $segment = self::consumeQuoted($script, $i, $length, '`', escapable: false);
                $current .= $segment;
                $i += strlen($segment);
                continue;
            }

            // 6. Dollar-quoted strings (`$$…$$` / `$tag$…$tag$`).
            if ($char === '$') {
                $tag = self::matchDollarTagStart($script, $i);
                if ($tag !== null) {
                    $closePos = strpos($script, $tag, $i + strlen($tag));
                    $segmentLength = $closePos === false
                        ? $length - $i
                        : ($closePos + strlen($tag)) - $i;
                    $current .= substr($script, $i, $segmentLength);
                    $i += $segmentLength;
                    continue;
                }
            }

            // 7. BEGIN…END compound-statement keyword tracking (MariaDB routine bodies — see class docblock).
            //    Only attempted at a word boundary so an identifier like "NewBeginning" is never mistaken for the
            //    keyword "BEGIN".
            if (ctype_alpha($char) && ($i === 0 || !self::isWordChar($script[$i - 1]))) {
                $keywordMatch = self::matchCompoundKeyword($script, $i, $length);
                if ($keywordMatch !== null) {
                    [$consumedText, $depthChange] = $keywordMatch;
                    $current .= $consumedText;
                    $i += strlen($consumedText);
                    $compoundDepth = max(0, $compoundDepth + $depthChange);
                    continue;
                }
            }

            $current .= $char;
            $i++;
        }

        self::pushStatement($statements, $current);

        return $statements;
    }

    /**
     * Trims $current and appends it to $statements unless it is empty or, once its comments are stripped, blank
     * (a standalone comment — e.g. a `DatabaseModel::getSql()` table-name banner line, or a trailing `-- …`
     * remark with no SQL before the next delimiter — is not an executable statement).
     *
     * @param string[] $statements
     */
    protected static function pushStatement(array &$statements, string $current): void
    {
        $trimmed = trim($current);
        if ($trimmed !== '' && !self::isBlankOrCommentOnly($trimmed)) {
            $statements[] = $trimmed;
        }
    }

    protected static function isBlankOrCommentOnly(string $statement): bool
    {
        $withoutBlockComments = (string)preg_replace('/\/\*.*?\*\//s', '', $statement);
        $withoutLineComments = (string)preg_replace('/^[ \t]*(?:--|#).*$/m', '', $withoutBlockComments);
        return trim($withoutLineComments) === '';
    }

    /**
     * Matches `DELIMITER <token>` at exactly position $i (case-insensitive, mysql-CLI convention: the token runs
     * to end of line). Returns [newDelimiter, consumedLength] or null.
     *
     * @return array{0: string, 1: int}|null
     */
    protected static function matchDelimiterCommand(string $script, int $i): ?array
    {
        if (preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*\r?\n?/i', $script, $matches, 0, $i) === 1) {
            return [$matches[1], strlen($matches[0])];
        }
        return null;
    }

    protected static function matchesAt(string $script, int $i, string $needle): bool
    {
        return substr_compare($script, $needle, $i, strlen($needle)) === 0;
    }

    /** `--` starts a comment only when followed by whitespace, EOL, or end of script (SQL standard). */
    protected static function isLineCommentStart(string $script, int $i, int $length): bool
    {
        if ($i + 1 >= $length || $script[$i + 1] !== '-') {
            return false;
        }
        if ($i + 2 >= $length) {
            return true;
        }
        return in_array($script[$i + 2], [' ', "\t", "\n", "\r"], true);
    }

    /**
     * Consumes a quoted region starting at the opening quote character, handling doubled-quote escaping
     * (`''`, `""`, `` `` ``) and, when $escapable, backslash escaping. An unterminated region consumes to the end
     * of the script rather than looping forever.
     */
    protected static function consumeQuoted(string $script, int $i, int $length, string $quoteChar, bool $escapable): string
    {
        $start = $i;
        $i++;
        while ($i < $length) {
            $char = $script[$i];
            if ($escapable && $char === '\\' && $i + 1 < $length) {
                $i += 2;
                continue;
            }
            if ($char === $quoteChar) {
                if ($i + 1 < $length && $script[$i + 1] === $quoteChar) {
                    $i += 2;
                    continue;
                }
                $i++;
                break;
            }
            $i++;
        }
        return substr($script, $start, $i - $start);
    }

    protected static function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    /**
     * Matches a compound-statement opener or closer keyword at exactly position $i (case-insensitive, trailing
     * `\b` so e.g. "BEGINNING" never matches "BEGIN"). Returns [consumedText, depthChange] or null when $i is not
     * the start of one of these keywords.
     *
     * @return array{0: string, 1: int}|null
     */
    protected static function matchCompoundKeyword(string $script, int $i, int $length): ?array
    {
        if (preg_match('/\G(BEGIN|CASE|LOOP|WHILE|REPEAT)\b/i', $script, $matches, 0, $i) === 1) {
            return [$matches[0], 1];
        }

        if (preg_match('/\GIF\b/i', $script, $matches, 0, $i) === 1) {
            $afterIf = $i + strlen($matches[0]);
            // "IF(" (optionally with whitespace before the paren) is the function call IF(cond, a, b); "IF
            // [NOT] EXISTS" is DDL's conditional-existence clause (DROP TRIGGER IF EXISTS, CREATE TABLE IF NOT
            // EXISTS) — neither is a flow-control block opener, so neither needs (or gets) a matching END IF.
            $j = $afterIf;
            while ($j < $length && ($script[$j] === ' ' || $script[$j] === "\t")) {
                $j++;
            }
            $isFunctionCall = $j < $length && $script[$j] === '(';
            $isExistsClause = preg_match('/\G(NOT[ \t]+)?EXISTS\b/i', $script, $ignored, 0, $j) === 1;
            return [$matches[0], ($isFunctionCall || $isExistsClause) ? 0 : 1];
        }

        if (preg_match('/\GEND\b/i', $script, $matches, 0, $i) === 1) {
            $consumed = $matches[0];
            // An immediately-following secondary keyword (END IF / END CASE / END LOOP / END WHILE / END REPEAT)
            // closes the SAME block as a bare END — consumed as one token so it is never re-processed as a fresh
            // opener.
            if (preg_match('/\G([ \t]+)(IF|CASE|LOOP|WHILE|REPEAT)\b/i', $script, $suffixMatches, 0, $i + strlen($consumed)) === 1) {
                $consumed .= $suffixMatches[0];
            }
            return [$consumed, -1];
        }

        return null;
    }

    /** Matches a dollar-quote opener (`$$` or `$tag$`) at exactly position $i. Returns the full tag text or null. */
    protected static function matchDollarTagStart(string $script, int $i): ?string
    {
        if (preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/', $script, $matches, 0, $i) === 1) {
            return $matches[0];
        }
        return null;
    }
}

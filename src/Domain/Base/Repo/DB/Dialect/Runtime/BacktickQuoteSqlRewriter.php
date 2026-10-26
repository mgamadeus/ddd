<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

/**
 * The pure text transform behind {@see BacktickQuoteMiddleware} (D1/§4.5): rewrites every backtick-quoted
 * identifier in raw SQL to a PostgreSQL double-quoted one — `` `Tracks`.`accountId` `` becomes
 * `"Tracks"."accountId"` — outside single-quoted string literals, `"`-quoted identifiers already present,
 * `--` line comments, `/* ... *\/` block comments (PostgreSQL nests them) and `$tag$ ... $tag$` dollar-quoted
 * bodies. A backtick is never doubled to escape itself in this codebase's raw SQL, so the rewrite is a plain
 * one-for-one character substitution once the scanner is in the "normal" state.
 *
 * Kept as its own class with a single static entry point so the lexer logic is unit-testable without a DBAL
 * connection; {@see BacktickQuoteConnection} is the only caller in production code.
 */
class BacktickQuoteSqlRewriter
{
    /** Fast path (§4.5): most statements carry no backtick at all, so skip the scan entirely. */
    public static function rewrite(string $sql): string
    {
        if (!str_contains($sql, '`')) {
            return $sql;
        }

        $length = strlen($sql);
        $result = '';
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'") {
                $scannedSegment = self::consumeQuoted($sql, $i, $length, "'");
                $result .= $scannedSegment->text;
                $i = $scannedSegment->nextIndex;
                continue;
            }

            if ($char === '"') {
                $scannedSegment = self::consumeQuoted($sql, $i, $length, '"');
                $result .= $scannedSegment->text;
                $i = $scannedSegment->nextIndex;
                continue;
            }

            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                $start = $i;
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $result .= substr($sql, $start, $i - $start);
                continue;
            }

            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $scannedSegment = self::consumeBlockComment($sql, $i, $length);
                $result .= $scannedSegment->text;
                $i = $scannedSegment->nextIndex;
                continue;
            }

            if ($char === '$') {
                $delimiter = self::matchDollarTagOpening($sql, $i);
                if ($delimiter !== null) {
                    $scannedSegment = self::consumeDollarQuoted($sql, $i, $length, $delimiter);
                    $result .= $scannedSegment->text;
                    $i = $scannedSegment->nextIndex;
                    continue;
                }
                $result .= $char;
                $i++;
                continue;
            }

            if ($char === '`') {
                $result .= '"';
                $i++;
                continue;
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }

    /** @return ScannedSqlSegment The consumed text incl. both quote chars, and the index right after it */
    protected static function consumeQuoted(string $sql, int $start, int $length, string $quoteChar): ScannedSqlSegment
    {
        $i = $start + 1;
        while ($i < $length) {
            if ($sql[$i] === $quoteChar) {
                // A doubled quote (''  or  "") escapes a literal quote char inside the literal/identifier.
                if ($i + 1 < $length && $sql[$i + 1] === $quoteChar) {
                    $i += 2;
                    continue;
                }
                $i++;
                break;
            }
            $i++;
        }

        return new ScannedSqlSegment(substr($sql, $start, $i - $start), $i);
    }

    /** @return ScannedSqlSegment The consumed comment incl. its delimiters (nested comments included), and the index right after it */
    protected static function consumeBlockComment(string $sql, int $start, int $length): ScannedSqlSegment
    {
        $i = $start + 2;
        $depth = 1;
        while ($i < $length && $depth > 0) {
            if ($sql[$i] === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $depth++;
                $i += 2;
                continue;
            }
            if ($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/') {
                $depth--;
                $i += 2;
                continue;
            }
            $i++;
        }

        return new ScannedSqlSegment(substr($sql, $start, $i - $start), $i);
    }

    /** The opening `$tag$` (tag optionally empty) at $sql[$pos], or null when $pos is just a bare '$' (a bind placeholder). */
    protected static function matchDollarTagOpening(string $sql, int $pos): ?string
    {
        if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $matches, 0, $pos) === 1) {
            return $matches[0];
        }

        return null;
    }

    /** @return ScannedSqlSegment The consumed body incl. both `$tag$` delimiters, and the index right after it */
    protected static function consumeDollarQuoted(string $sql, int $start, int $length, string $delimiter): ScannedSqlSegment
    {
        $bodyStart = $start + strlen($delimiter);
        $closingAt = strpos($sql, $delimiter, $bodyStart);
        if ($closingAt === false) {
            // Unterminated — copy the rest verbatim rather than risk rewriting a backtick that is really inside it.
            return new ScannedSqlSegment(substr($sql, $start), $length);
        }
        $end = $closingAt + strlen($delimiter);

        return new ScannedSqlSegment(substr($sql, $start, $end - $start), $end);
    }
}

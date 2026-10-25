<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * {@see ConflictTargetResolver::resolve()}'s outcome (dual-dialect architecture plan §4.6, Mig §3.3). Either:
 * - a single arbiter {@see self::$columns} ready for `ON CONFLICT (<columns>)` (empty means a plain `INSERT`,
 *   no unique key applies at all) — {@see self::target()};
 * - or, case 4 ambiguous AND at least one ambiguous candidate key has a nullable column (so no single
 *   compile-time arbiter is safe to force on every row, attribute or not — Mig §3.3 "Runtime fallback for the
 *   nullable cases"), a request for the caller to run the fallback instead: a target-less `INSERT … ON CONFLICT
 *   DO NOTHING RETURNING id`, then — only if that returned no row — a lookup of the row that actually collided,
 *   trying {@see self::$candidateKeys} in order — {@see self::nullableFallback()}. The lookup/update execution
 *   itself lives in {@see PostgresUpsertBuilder} (it needs a live connection); this object only carries the
 *   candidate keys to try.
 */
final class ConflictTargetResolution
{
    private function __construct(
        public readonly array $columns,
        public readonly bool $needsNullableFallback,
        public readonly array $candidateKeys,
    ) {
    }

    /** @param string[] $columns */
    public static function target(array $columns): self
    {
        return new self($columns, false, []);
    }

    /** @param string[][] $candidateKeys Every ambiguous candidate UNIQUE key (column lists), in declaration order. */
    public static function nullableFallback(array $candidateKeys): self
    {
        return new self([], true, $candidateKeys);
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * Outcome of {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::upsert()} (dual-dialect architecture plan §4.3):
 * the row's id (the caller's explicit {@see UpsertSpec::$idValue} when one was given, else the generated id) and
 * whether this call inserted a fresh row or updated an existing one via the conflict target.
 */
final class UpsertResult
{
    public function __construct(
        public readonly int|string|null $id,
        public readonly bool $inserted,
    ) {
    }
}

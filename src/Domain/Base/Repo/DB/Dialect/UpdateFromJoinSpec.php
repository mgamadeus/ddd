<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

/**
 * Dialect-neutral description of an "UPDATE one table from a join" statement (dual-dialect architecture plan
 * §4.3 `SqlDialect::updateFromJoin()`) — app code that writes `UPDATE t JOIN j ON … SET t.c = j.c WHERE …`
 * directly today, which has no PostgreSQL form (`UPDATE … SET … FROM … WHERE` instead; no `JOIN` keyword at all
 * in an `UPDATE`). Every SQL fragment here is caller-supplied text (column/table names already quoted via
 * {@see SqlDialect::quote()}, values already bound as `?` placeholders in `$whereSql`/`$setAssignments`/
 * `$joinOnSql` by the caller) — this value object only carries WHERE each fragment goes, not how it is built.
 */
final class UpdateFromJoinSpec
{
    /**
     * @param array<string, string> $setAssignments unquoted target column name => value SQL expression (may
     *   reference `$joinAlias`, e.g. `j."price"`); rendered on the LEFT as `$targetAlias."col"` on both engines.
     */
    public function __construct(
        public readonly string $targetTable,
        public readonly string $targetAlias,
        public readonly string $joinTable,
        public readonly string $joinAlias,
        public readonly string $joinOnSql,
        public readonly array $setAssignments,
        public readonly ?string $whereSql = null,
    ) {
    }
}

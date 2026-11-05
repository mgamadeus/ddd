<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

/**
 * D14 (dual-dialect architecture plan `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §3, D14):
 * which PostgreSQL fulltext rendering a `#[Translatable(fullTextIndex: true)]` or `#[DatabaseIndex(TYPE_FULLTEXT)]`
 * declaration gets. Has NO effect on MariaDB — both modes render today's `FULLTEXT INDEX` + `MATCH … AGAINST`
 * (golden unchanged); the dialect-specific DDL renderer and QueryOptions renderer are the only readers.
 *
 * - `NAME` (default): short text, name search — `pg_trgm` + `unaccent` (`ddd_fold`), GIN trigram index, prefix match.
 * - `DOCUMENT`: long text — `to_tsvector('simple', ddd_unaccent(col))` + `websearch_to_tsquery`, GIN expression index.
 * - `TOKEN_MATCH` (D19/§4.8, Mig §3.5, step 5): a whitespace-joined token-list column (e.g. a list of allocation tokens)
 *   that keeps its plain text column and data on both engines — no normalisation table. MariaDB still renders the
 *   ordinary `FULLTEXT INDEX` (golden unchanged, exactly like the other two modes); PostgreSQL renders
 *   `USING gin (string_to_array(col, ' '))`, the identical expression `TOKEN_MATCH()`'s query-side rendering
 *   ({@see \DDD\Domain\Base\Repo\DB\Dialect\Query\TokenMatch}) reads — or the planner ignores the index.
 *
 * A mode change is an index change in the schema diff (step 7).
 */
enum FullTextMode: string
{
    case NAME = 'NAME';
    case DOCUMENT = 'DOCUMENT';
    case TOKEN_MATCH = 'TOKEN_MATCH';
}

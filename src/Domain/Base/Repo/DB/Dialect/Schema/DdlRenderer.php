<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

/**
 * One per engine (D9/§4.2). Step 4a (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §6 row 4a) ships the CREATE path only —
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::ddlRenderer()} is this interface's only caller today. ALTER
 * (`DROP`/`MODIFY`/`NOT VALID`+`VALIDATE`), the schema introspector and the phase-ordered diff assembler are build
 * step 7 — additions to this interface, never a reason to stub what step 7 needs.
 */
interface DdlRenderer
{
    /**
     * Idempotent infrastructure objects this renderer's CREATE-path SQL depends on (extensions, collation,
     * `ddd_*` SQL functions) — safe to run on every test bootstrap, every copy, every diff apply without
     * conditionals on the caller's side (PostgreSQL: `CREATE EXTENSION IF NOT EXISTS …`, `CREATE COLLATION IF NOT
     * EXISTS …`, `CREATE OR REPLACE FUNCTION …`). Empty on MariaDB, which has no such per-database infra objects.
     *
     * @return DdlStatement[]
     */
    public function ensure(): array;

    /**
     * CREATE-path statements for one entity's table: columns (incl. `STORED` generated columns), primary key,
     * indexes, foreign keys. Returned already in a safe standalone execution order; `phase` + `table` additionally
     * let a caller batch many models' statements together via {@see DdlStatement::sortByPhase()} (needed once a
     * foreign key references a table that has not been created yet in entity-scan order — see
     * `PostgresDdlRenderer`'s class docblock). Returns `[]` for a single-table-inheritance subclass (no table of
     * its own — mirrors {@see DatabaseModel::getSql()}).
     *
     * @return DdlStatement[]
     */
    public function renderCreate(DatabaseModel $databaseModel): array;
}

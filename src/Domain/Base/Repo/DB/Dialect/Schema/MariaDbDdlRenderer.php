<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

/**
 * Thin wrapper over today's {@see DatabaseModel::getSql()} (Arch §4.10: "`MariaDbDdlRenderer` = today's `getSql()`
 * (kept as a deprecated shim for the golden)"). Built only because {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect}
 * needs one `ddlRenderer()` per engine (D9) — NOT because anything in step 4a's gates calls it: the T0 golden suite
 * (`tests/GoldenDdl/DdlGoldenTest.php`) and the MariaDB harness (`IntegrationTestCase::setUpBeforeClass()`) both
 * still call `DatabaseModel::getSql()` directly and are untouched by this class, so the golden byte-identity
 * guarantee never routes through here.
 *
 * `getSql()`'s one string already interleaves CREATE TABLE, the virtual-column ALTER, every index and every foreign
 * key for one table — MariaDB's own ordering, unchanged since v2.70.10. Splitting that blob into separate
 * per-phase `DdlStatement`s (so it could take part in {@see DdlStatement::sortByPhase()} cross-table batching the
 * way `PostgresDdlRenderer` does) is step 7 work, alongside the rest of the ALTER-path renderer; doing it now would
 * mean re-deriving MariaDB's phase boundaries from a plain SQL string with no gate yet to prove the split is
 * lossless. One `DdlStatement` per table, phase `PHASE_CREATE_TABLE`, is therefore the entire mapping: correct, and
 * exactly as safe as the string it wraps.
 */
final class MariaDbDdlRenderer implements DdlRenderer
{
    public function ensure(): array
    {
        return [];
    }

    public function renderCreate(DatabaseModel $databaseModel): array
    {
        $sql = $databaseModel->getSql();
        if ($sql === '') {
            // Single-table-inheritance subclass: no table of its own (DatabaseModel::getSql() docblock).
            return [];
        }

        return [
            new DdlStatement(
                sql: $sql,
                phase: DdlStatement::PHASE_CREATE_TABLE,
                table: $databaseModel->sqlTableName
            ),
        ];
    }
}

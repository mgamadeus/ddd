<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Domain\Base\Repo\DB\Dialect\Write\BindExpressionKind;
use DDD\Domain\Base\Repo\DB\Dialect\Write\ColumnWrite;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpdatePolicy;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertSpec;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\AmbiguousConflictRow;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\ConflictAttributeRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Two PostgreSQL write-path gaps (dual-dialect architecture plan §4.6, Mig §3.3) closed on branch `postgres`:
 * `#[UpsertConflictKey]` for an entity with more than one fully-set UNIQUE key, and identity catch-up after an
 * explicit-id insert. Both have a MariaDB-parity half (the engine never needed either mechanism in the first
 * place) and a PostgreSQL-only half (where the gap actually lived) — every test below runs on both drivers and
 * documents which half it is asserting.
 *
 * {@see AmbiguousConflictRow} / {@see ConflictAttributeRow} are NOT part of {@see IntegrationTestCase::FIXTURE_ENTITY_CLASSES}
 * on purpose: that constant is read with `self::`, not `static::`, so a subclass cannot extend it, and adding to
 * it directly would grow every OTHER integration test class's fixture-DDL capture — this class manages its own
 * two extra tables instead ({@see self::createExtraFixtureTable()}), on top of the three parent::setUpBeforeClass()
 * already creates (which is where {@see CounterRow}, used by the identity-catch-up tests, comes from).
 */
final class ConflictKeyAndIdentityCatchUpTest extends IntegrationTestCase
{
    private const array EXTRA_FIXTURE_ENTITY_CLASSES = [AmbiguousConflictRow::class, ConflictAttributeRow::class];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        foreach (self::EXTRA_FIXTURE_ENTITY_CLASSES as $entityClass) {
            static::createExtraFixtureTable($entityClass);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (self::EXTRA_FIXTURE_ENTITY_CLASSES as $entityClass) {
                static::dropExtraFixtureTable($entityClass);
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::EXTRA_FIXTURE_ENTITY_CLASSES as $entityClass) {
            $connection = static::connection();
            $connection->executeStatement(
                'DELETE FROM ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
            );
        }
    }

    /** Mirrors {@see IntegrationTestCase::createFixtureTablesOnPostgres()}/the MariaDB branch, for one extra entity. */
    protected static function createExtraFixtureTable(string $entityClass): void
    {
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        if (static::currentDriver() === 'pdo_pgsql') {
            $dialect = static::dialect();
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote($databaseModel->sqlTableName) . ' CASCADE');
            $statements = $dialect->ddlRenderer()->renderCreate($databaseModel);
            foreach (DdlStatement::sortByPhase($statements) as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        $connection->executeStatement('DROP TABLE IF EXISTS `' . $databaseModel->sqlTableName . '`');
        foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    protected static function dropExtraFixtureTable(string $entityClass): void
    {
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        $connection->executeStatement(
            'DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
            . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
        );
    }

    protected function countRows(string $table): int
    {
        return (int)static::connection()->fetchOne('SELECT COUNT(*) FROM ' . static::quoteIdentifier($table));
    }

    // ───────────────────────────────────────────────────────── #[UpsertConflictKey]

    /**
     * Structural, not a real collision: `resolve()` must pick an arbiter before the first row of this shape is
     * even written (PostgreSQL's `ON CONFLICT` needs its target named up front). MariaDB has no such requirement
     * — `ON DUPLICATE KEY UPDATE` only matters once a key actually collides — so the SAME write succeeds there
     * even though two independent UNIQUE keys are both set; that is the documented engine difference §3.3
     * case 4 exists for.
     */
    public function testTwoAmbiguousNotNullBackedKeysWithoutTheAttribute(): void
    {
        $row = new AmbiguousConflictRow();
        $row->handle = 'h1';
        $row->code = 'c1';
        $row->label = 'first';

        if (static::currentDriver() === 'pdo_pgsql') {
            try {
                $row->update();
                $this->fail('PostgreSQL must refuse an ambiguous arbiter with no #[UpsertConflictKey] declared.');
            } catch (InternalErrorException $exception) {
                $this->assertStringContainsString(AmbiguousConflictRow::class, $exception->getMessage());
                $this->assertStringContainsString('(handle)', $exception->getMessage());
                $this->assertStringContainsString('(code)', $exception->getMessage());
                $this->assertStringContainsString('UpsertConflictKey', $exception->getMessage());
            }
            return;
        }

        $row->update();
        $this->assertNotEmpty($row->id);
        $this->assertSame(1, $this->countRows('AmbiguousConflictRows'));
    }

    /**
     * The golden-equivalence case: with the declared key, a PostgreSQL write that collides on `handle` now
     * merges exactly like MariaDB's `ON DUPLICATE KEY UPDATE` does — one row survives, carrying the SECOND
     * write's `code`. Without the fix this would either throw (ambiguous) on PostgreSQL, or — had the resolver
     * picked `code` instead of the declared `handle` — succeed as a second INSERT and then fail on `code`'s own
     * UNIQUE constraint the moment two rows shared a `handle` with different `code`s, which is exactly what this
     * test's two writes do.
     */
    public function testTheAttributeMakesTheUpsertHitItsDeclaredKeyOnBothEngines(): void
    {
        $first = new ConflictAttributeRow();
        $first->handle = 'device-1';
        $first->code = 'audience-a';
        $first->label = 'first write';
        $first->update();

        $second = new ConflictAttributeRow();
        $second->handle = 'device-1'; // same declared arbiter → must merge onto the same row
        $second->code = 'audience-b'; // different value for the OTHER unique key → would 23505 if misrouted
        $second->label = 'second write';
        $second->update();

        $this->assertSame(1, $this->countRows('ConflictAttributeRows'), 'the second write must update, not insert');
        $storedRow = static::connection()->fetchAssociative(
            'SELECT * FROM ' . static::quoteIdentifier('ConflictAttributeRows')
        );
        $this->assertSame('device-1', $storedRow['handle']);
        $this->assertSame('audience-b', $storedRow['code']);
        $this->assertSame('second write', $storedRow['label']);
    }

    // ───────────────────────────────────────────────────────── identity catch-up

    /**
     * MariaDB's `AUTO_INCREMENT` already bumps its counter past any explicitly-inserted id as part of ordinary
     * engine behaviour — this test passes there with no change at all. On PostgreSQL, without the catch-up, the
     * explicit insert below succeeds but leaves the sequence exactly where it was; the FOLLOWING plain insert
     * then lets the sequence hand out the SAME id a second time — a `23505` primary-key violation this test
     * would surface as a thrown exception instead of a clean, distinct id.
     */
    public function testAnExplicitIdInsertFollowedByAGeneratedIdInsertDoesNotCollide(): void
    {
        $baseline = new CounterRow();
        $baseline->businessKey = 'catchup-baseline';
        $baseline->amount = 1;
        $baseline->update();
        $this->assertNotEmpty($baseline->id);

        $nextGeneratedIdWouldBe = $baseline->id + 1;

        $explicit = new CounterRow();
        $explicit->id = $nextGeneratedIdWouldBe; // exactly the id the NEXT plain insert would otherwise receive
        $explicit->businessKey = 'catchup-explicit';
        $explicit->amount = 2;
        $explicit->update();
        $this->assertSame($nextGeneratedIdWouldBe, $explicit->id);

        $generated = new CounterRow();
        $generated->businessKey = 'catchup-generated';
        $generated->amount = 3;
        $generated->update(); // must not throw a unique-violation on "id"

        $this->assertNotEmpty($generated->id);
        $this->assertNotSame($explicit->id, $generated->id);
        $this->assertSame(3, $this->countRows('CounterRows'));
    }

    /**
     * `RawUpsertExecutor`/`insertOrNull()` path (dual-dialect architecture plan §4.3) — the raw-SQL sibling of
     * the entity write above, covered the same way via {@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::upsert()}.
     */
    public function testAnExplicitIdRawUpsertCatchesUpTheSequenceToo(): void
    {
        $connection = static::connection();
        $dialect = static::dialect();

        $baseline = new CounterRow();
        $baseline->businessKey = 'raw-catchup-baseline';
        $baseline->amount = 1;
        $baseline->update();

        $explicitId = $baseline->id + 1;
        $spec = new UpsertSpec(
            table: 'CounterRows',
            columns: [
                new ColumnWrite('businessKey', 'raw-catchup-explicit', 'string', BindExpressionKind::PLAIN, null, UpdatePolicy::REPLACE, null),
            ],
            idValue: $explicitId,
            conflictTargetColumns: ['id'],
        );
        $result = $dialect->upsert($connection, $spec);
        $this->assertSame($explicitId, $result->id);

        $generated = new CounterRow();
        $generated->businessKey = 'raw-catchup-generated';
        $generated->amount = 3;
        $generated->update();

        $this->assertNotSame($explicitId, $generated->id);
    }
}

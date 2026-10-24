<?php

declare(strict_types=1);

namespace DDD\Tests\GoldenDdl;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRow;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use PHPUnit\Framework\TestCase;

/**
 * T0 golden, no-DB slice (§6 step 1 "golden" testsuite, `tests/golden/README.md` "How the later compare works"):
 * the fixture DDL phases (`<Class>__setUpBeforeClass.sql` / `<Class>__tearDownAfterClass.sql`) are produced by
 * {@see DatabaseModel::getSql()} alone — pure PHP, no platform/server-version branch, no connection — so they are
 * reconstructed and compared byte-identically WITHOUT a database at all, unlike the other 43 golden files (the
 * recorded test-method bodies), which exercise real Doctrine/DBAL execution (lastInsertId, hydrated read results
 * feeding later branches, …) and are not reconstructable this way. Per the plan's own escape hatch ("If a
 * byte-identical no-DB compare is impossible for some golden files … compare those by re-running the recording
 * against the MariaDB harness instead"), those 43 are instead verified by re-running
 * `DDD_GOLDEN_RECORD=<scratch dir> DDD_TEST_DRIVER=pdo_mysql vendor/bin/phpunit -c phpunit.integration.xml` against
 * the live harness and diffing the result against `tests/golden/mariadb` (see `tests/golden/README.md`
 * "Re-capturing") — not part of this no-DB suite, which only needs PHP and the fixture entity classes.
 *
 * One golden file is excluded even from the DDL-only compare for a documented reason: see
 * {@see self::EXCLUDED_SET_UP_FILES}.
 */
final class DdlGoldenTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__ . '/../golden/mariadb';

    /** @var string[] Every IntegrationTestCase subclass's T0 capture; setUpBeforeClass/tearDownAfterClass is identical across all six */
    private const array GOLDEN_TEST_CLASSES = [
        'BatchUpsertTest',
        'FilterAgainstDatabaseTest',
        'HarnessSmokeTest',
        'PartialUpdateTest',
        'RunReadOnlyQueryTest',
        'TransactionSafeConnectionTest',
    ];

    /**
     * RunReadOnlyQueryTest__setUpBeforeClass.sql has one extra leading "SELECT 1" statement that every other
     * class's setUpBeforeClass.sql lacks (verified: `diff` against HarnessSmokeTest's capture shows only that one
     * line pair) — a connection-health ping `RunReadOnlyQuery`'s `CommandTester` triggers on first use, not DDL.
     * That is exactly a "live round trip" result this no-DB suite cannot reconstruct; it stays covered by the
     * live-harness re-capture diff described in the class docblock instead of being silently dropped from the gate.
     */
    private const array EXCLUDED_SET_UP_FILES = ['RunReadOnlyQueryTest'];

    public static function fixtureEntityClasses(): array
    {
        return [CounterRow::class, TranslatedRow::class, SpatialRow::class];
    }

    /** @return array<string, array{0: string}> */
    public static function goldenTestClasses(): array
    {
        $cases = [];
        foreach (self::GOLDEN_TEST_CLASSES as $className) {
            $cases[$className] = [$className];
        }
        return $cases;
    }

    /** @dataProvider goldenTestClasses */
    public function testSetUpBeforeClassDdlIsByteIdenticalToTheGoldenCapture(string $testClassName): void
    {
        if (in_array($testClassName, self::EXCLUDED_SET_UP_FILES, true)) {
            $this->markTestSkipped(
                "{$testClassName}__setUpBeforeClass.sql carries a live connection ping ahead of its DDL; not "
                . 'reconstructable without a database (see class docblock) — verified by the live re-capture diff instead.'
            );
        }

        $statements = [];
        foreach (self::fixtureEntityClasses() as $fixtureEntityClass) {
            $databaseModel = DatabaseModel::fromEntityClass($fixtureEntityClass);
            $statements[] = 'DROP TABLE IF EXISTS `' . $databaseModel->sqlTableName . '`';
            foreach (IntegrationTestCaseSqlSplitter::splitSqlStatements($databaseModel->getSql()) as $statement) {
                $statements[] = $statement;
            }
        }

        $this->assertSame(
            $this->readGoldenFile("{$testClassName}__setUpBeforeClass.sql"),
            self::renderGoldenBuffer($statements)
        );
    }

    /** @dataProvider goldenTestClasses */
    public function testTearDownAfterClassDdlIsByteIdenticalToTheGoldenCapture(string $testClassName): void
    {
        $statements = [];
        foreach (self::fixtureEntityClasses() as $fixtureEntityClass) {
            $statements[] = 'DROP TABLE IF EXISTS `' . DatabaseModel::fromEntityClass($fixtureEntityClass)->sqlTableName . '`';
        }

        $this->assertSame(
            $this->readGoldenFile("{$testClassName}__tearDownAfterClass.sql"),
            self::renderGoldenBuffer($statements)
        );
    }

    private function readGoldenFile(string $fileName): string
    {
        $path = self::GOLDEN_DIR . '/' . $fileName;
        $this->assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /** @param string[] $statements Mirrors RecordingMiddleware::record()/endTest() with every params list empty */
    private static function renderGoldenBuffer(array $statements): string
    {
        $buffer = array_map(
            static fn (string $sql): string => rtrim($sql) . "\n-- params: []\n-- ----------------------------------------",
            $statements
        );
        return implode("\n", $buffer) . "\n";
    }
}

/** Test-only duplicate of IntegrationTestCase::splitSqlStatements() — this suite must not need tests/Integration's heavier base. */
final class IntegrationTestCaseSqlSplitter
{
    /** @return string[] */
    public static function splitSqlStatements(string $sql): array
    {
        $sqlWithoutComments = preg_replace('/^\s*#.*$/m', '', $sql);
        $statements = [];
        foreach (preg_split('/;\s*$/m', $sqlWithoutComments) as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }
        return $statements;
    }
}

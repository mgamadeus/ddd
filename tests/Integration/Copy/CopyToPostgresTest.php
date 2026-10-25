<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Copy;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Symfony\Commands\Base\Database\CopyToPostgres;
use DDD\Tests\Integration\Fixtures\Entities\CopyRows\CopyRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:db:copy-to-postgres` end to end against the real harness containers (Mig §5.1/§5.5): a live MariaDB source
 * filled with edge-case rows, a live PostgreSQL target reached through the pdo_pgsql default-scope connection
 * exactly as the command resolves it. pdo_pgsql only — the command's target IS the default-scope connection, so
 * this suite needs `DDD_TEST_DRIVER=pdo_pgsql`; the source is a second, independent raw connection to the SAME
 * MariaDB container the pdo_mysql suites use (`tests/Integration/docker-compose.yml`).
 *
 * Run (both containers up): `DDD_TEST_DRIVER=pdo_pgsql php -d memory_limit=2G vendor/bin/phpunit
 * -c phpunit.integration.xml --group copy`.
 *
 * @group copy
 */
final class CopyToPostgresTest extends TestCase
{
    /** @var string[] Deliberately NOT IntegrationTestCase::FIXTURE_ENTITY_CLASSES — see CopyRow's own docblock. */
    private const array ENTITY_CLASSES = [CopyRow::class, CounterRow::class];

    private const string ORPHAN_TABLE = 'LegacyOrphans';

    private ?Connection $source = null;

    public static function setUpBeforeClass(): void
    {
        if (self::currentDriver() !== 'pdo_pgsql') {
            return;
        }
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, LazyLoadRepo::DB);
    }

    protected function setUp(): void
    {
        if (self::currentDriver() !== 'pdo_pgsql') {
            $this->markTestSkipped(
                'pdo_pgsql only: the command\'s target is the pdo_pgsql default-scope connection '
                . '(start tests/Integration/docker-compose.yml and set DDD_TEST_DRIVER=pdo_pgsql).'
            );
        }
        $this->source = self::openSourceConnection();
        $this->resetSourceSchema();
        $this->resetTargetSchema();
    }

    protected function tearDown(): void
    {
        $this->source?->close();
    }

    private static function currentDriver(): ?string
    {
        return getenv('DDD_TEST_DRIVER') ?: ($_SERVER['DDD_TEST_DRIVER'] ?? null);
    }

    private static function openSourceConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => (int)($_SERVER['DDD_TEST_DB_PORT'] ?? getenv('DDD_TEST_DB_PORT') ?: 3317),
            'user' => 'root',
            'password' => 'ddd-test',
            'dbname' => 'ddd_test',
            'charset' => 'utf8mb4',
        ]);
    }

    /** @return DatabaseModel[] */
    private static function databaseModels(): array
    {
        return array_map(static fn (string $class): DatabaseModel => DatabaseModel::fromEntityClass($class), self::ENTITY_CLASSES);
    }

    private function resetSourceSchema(): void
    {
        $this->source->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::databaseModels() as $databaseModel) {
            $this->source->executeStatement('DROP TABLE IF EXISTS `' . $databaseModel->sqlTableName . '`');
            foreach ($this->splitStatements($databaseModel->getSql()) as $statement) {
                $this->source->executeStatement($statement);
            }
        }
        $this->source->executeStatement('DROP TABLE IF EXISTS `' . self::ORPHAN_TABLE . '`');
        $this->source->executeStatement(
            'CREATE TABLE `' . self::ORPHAN_TABLE . '` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `note` VARCHAR(255))'
        );
        $this->source->executeStatement('INSERT INTO `' . self::ORPHAN_TABLE . '` (`note`) VALUES (\'no entity owns this table\')');
    }

    /** @return string[] */
    private function splitStatements(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*#.*$/m', '', $sql);
        $statements = [];
        foreach (preg_split('/;\s*$/m', $withoutComments) as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }
        return $statements;
    }

    private function resetTargetSchema(): void
    {
        $target = self::targetConnection();
        foreach (self::databaseModels() as $databaseModel) {
            $target->executeStatement('DROP TABLE IF EXISTS "' . $databaseModel->sqlTableName . '" CASCADE');
        }
    }

    private static function targetConnection(): Connection
    {
        return \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::getInstance()->getConnection();
    }

    private function seedCopyRows(): void
    {
        $fullJson = json_encode(
            ['a' => 1, 'b' => "x\ty\nz\\slash", 'u' => "café"],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $largeText = str_repeat('Lorem ipsum dolor sit amet, ', 500); // ~14.5 KB

        $insert = $this->source->prepare(
            'INSERT INTO `CopyRows` (`label`, `largeText`, `binaryData`, `flag`, `payload`, `location`, `embedding`, `occurredAt`, `amount`) '
            . 'VALUES (?, ?, ?, ?, ?, ST_PointFromText(?), VEC_FromText(?), ?, ?)'
        );

        // Row 1: every edge value populated.
        $insert->bindValue(1, 'full row');
        $insert->bindValue(2, $largeText);
        $insert->bindValue(3, "Hello\x00\x01Bin", \PDO::PARAM_LOB);
        $insert->bindValue(4, 1, \PDO::PARAM_INT);
        $insert->bindValue(5, $fullJson);
        $insert->bindValue(6, 'POINT(1.5 2.5)');
        $insert->bindValue(7, '[0.1,0.2,0.3,0.4]');
        $insert->bindValue(8, '2024-05-01 12:30:00');
        $insert->bindValue(9, 42, \PDO::PARAM_INT);
        $insert->execute();

        // Row 2: every nullable column NULL (embedding stays NOT NULL per the DDD VECTOR convention).
        $this->source->executeStatement(
            'INSERT INTO `CopyRows` (`label`, `largeText`, `binaryData`, `flag`, `payload`, `location`, `embedding`, `occurredAt`, `amount`) '
            . 'VALUES (NULL, NULL, NULL, 0, NULL, NULL, VEC_FromText(\'[0,0,0,0]\'), NULL, NULL)'
        );

        // Row 3: zero-date in the nullable `occurredAt` column (allowed under this sql_mode — no NO_ZERO_DATE).
        $this->source->executeStatement(
            'INSERT INTO `CopyRows` (`label`, `flag`, `embedding`, `occurredAt`) '
            . 'VALUES (\'zero date\', 1, VEC_FromText(\'[1,1,1,1]\'), \'0000-00-00 00:00:00\')'
        );
    }

    private function seedCounterRows(): void
    {
        $this->source->executeStatement(
            'INSERT INTO `CounterRows` (`businessKey`, `counter`, `keptValue`, `label`, `amount`, `ratio`, `isActive`, `dueOn`, `occurredAt`, `displayOrder`) '
            . 'VALUES (\'biz-1\', 3, 7, \'first\', 100, 1.5, 1, \'2024-01-01\', \'2024-01-01 09:00:00\', 1), '
            . '(\'biz-2\', 0, 0, NULL, NULL, NULL, 0, NULL, NULL, 2)'
        );
    }

    /** @return array{0: int, 1: string} */
    private function runCommand(array $input): array
    {
        $tester = new CommandTester(new CopyToPostgres(self::ENTITY_CLASSES));
        $exitCode = $tester->execute($input + ['--source-dsn' => $this->sourceDsn(), '--target-db-allow-pattern' => 'ddd_test']);
        return [$exitCode, $tester->getDisplay()];
    }

    private function sourceDsn(): string
    {
        $port = (int)($_SERVER['DDD_TEST_DB_PORT'] ?? getenv('DDD_TEST_DB_PORT') ?: 3317);
        return "mysql://root:ddd-test@127.0.0.1:$port/ddd_test";
    }

    public function testAllPhasesCopyAndVerifyEveryEdgeCaseRow(): void
    {
        $this->seedCopyRows();
        $this->seedCounterRows();

        [$exitCode, $display] = $this->runCommand(['--phase' => 'all']);

        $this->assertStringContainsString('skipped: no entity for table(s)', $display);
        $this->assertStringContainsString(self::ORPHAN_TABLE, $display);
        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('PASS', $display);
        $this->assertStringNotContainsString('FAIL', $display);

        $target = self::targetConnection();
        $this->assertSame(3, (int)$target->fetchOne('SELECT COUNT(*) FROM "CopyRows"'));
        $this->assertSame(2, (int)$target->fetchOne('SELECT COUNT(*) FROM "CounterRows"'));

        // The zero-date row's occurredAt must have become NULL, not an error, not a literal zero-date.
        $occurredAt = $target->fetchOne('SELECT "occurredAt" FROM "CopyRows" WHERE "label" = \'zero date\'');
        $this->assertNull($occurredAt);

        // The full row's JSON, geometry and vector must be byte/value-exact.
        $full = $target->fetchAssociative(
            'SELECT "payload", encode(ST_AsBinary("location"),\'hex\') AS loc_hex, "embedding"::text AS vec_text, encode("binaryData",\'hex\') AS bin_hex '
            . 'FROM "CopyRows" WHERE "label" = \'full row\''
        );
        $this->assertSame('{"a":1,"b":"x\ty\nz\\\\slash","u":"café"}', $full['payload']);
        $this->assertSame('0101000000000000000000f83f0000000000000440', $full['loc_hex']);
        $this->assertSame('[0.1,0.2,0.3,0.4]', $full['vec_text']);
        $this->assertSame(bin2hex("Hello\x00\x01Bin"), $full['bin_hex']);

        // UNIQUE index on CounterRows.businessKey must exist after finalise.
        $uniqueIndexCount = (int)$target->fetchOne(
            "SELECT COUNT(*) FROM pg_indexes WHERE tablename = 'CounterRows' AND indexdef ILIKE '%UNIQUE%'"
        );
        $this->assertGreaterThan(0, $uniqueIndexCount);
    }

    public function testACorruptedTargetRowFailsVerify(): void
    {
        $this->seedCopyRows();
        $this->seedCounterRows();

        [$exitCode] = $this->runCommand(['--phase' => 'all']);
        $this->assertSame(0, $exitCode);

        self::targetConnection()->executeStatement('UPDATE "CopyRows" SET "amount" = 999999 WHERE "label" = \'full row\'');

        [$verifyExitCode, $verifyDisplay] = $this->runCommand(['--phase' => 'verify']);
        $this->assertSame(1, $verifyExitCode);
        $this->assertStringContainsString('FAIL', $verifyDisplay);
        $this->assertStringContainsString('MISMATCH', $verifyDisplay);
    }

    public function testDryRunTouchesNothing(): void
    {
        [$exitCode, $display] = $this->runCommand(['--phase' => 'schema', '--dry-run' => true]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[dry-run]', $display);

        $exists = self::targetConnection()->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'CopyRows'"
        );
        $this->assertFalse($exists);
    }
}

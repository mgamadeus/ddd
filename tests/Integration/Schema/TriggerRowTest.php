<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Schema;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Tests\Integration\Fixtures\Entities\TriggerRows\TriggerRow;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/**
 * Step 7 trigger slice gate (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.10): proves the real BEFORE INSERT
 * refusal fires, with the expected message, on BOTH engines — MariaDB via {@see TriggerRow}'s golden
 * `TriggerRowBeforeInsertTrigger.sql` (unchanged generator path, `DatabaseModel::getSql()`), PostgreSQL via its
 * `….pgsql.sql` sibling rendered by {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer}.
 *
 * Deliberately standalone (not an {@see \DDD\Tests\Integration\IntegrationTestCase} subclass, matching
 * {@see \DDD\Tests\Integration\Harness\PostgresDdlBootstrapTest}'s pattern): that base class's MariaDB bootstrap
 * path splits `DatabaseModel::getSql()` with a plain `explode`-style regex
 * ({@see \DDD\Tests\Integration\IntegrationTestCase::splitSqlStatements()}) which a `BEGIN…END` trigger body would
 * break — this class manages {@see TriggerRow}'s table on its own, through
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer} instead, so the fixture never needs to join the
 * shared `FIXTURE_ENTITY_CLASSES` list every other integration test class also bootstraps.
 *
 * Run: `docker compose -f tests/Integration/docker-compose.yml up -d --wait` then, per driver:
 * `DDD_TEST_DRIVER=pdo_mysql|pdo_pgsql php -d memory_limit=2G vendor/bin/phpunit -c phpunit.integration.xml
 * tests/Integration/Schema/TriggerRowTest.php`.
 */
final class TriggerRowTest extends TestCase
{
    private static function currentDriver(): ?string
    {
        return getenv('DDD_TEST_DRIVER') ?: ($_SERVER['DDD_TEST_DRIVER'] ?? null);
    }

    private static function isEnabled(): bool
    {
        return in_array(self::currentDriver(), ['pdo_mysql', 'pdo_pgsql'], true);
    }

    private static function table(): string
    {
        return DatabaseModel::fromEntityClass(TriggerRow::class)->sqlTableName;
    }

    public static function setUpBeforeClass(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, LazyLoadRepo::DB);

        $connection = EntityManagerFactory::getInstance()->getConnection();
        $databaseModel = DatabaseModel::fromEntityClass(TriggerRow::class);

        if (self::currentDriver() === 'pdo_pgsql') {
            $dialect = EntityManagerFactory::getDialect();
            $renderer = $dialect->ddlRenderer();
            $connection->executeStatement('DROP TABLE IF EXISTS "' . $databaseModel->sqlTableName . '" CASCADE');
            foreach ($renderer->ensure() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            foreach (DdlStatement::sortByPhase($renderer->renderCreate($databaseModel)) as $statement) {
                $connection->executeStatement($statement->sql);
            }
        } else {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $databaseModel->sqlTableName . '`');
            // The exact generator-gap scenario Arch §4.10 names: DatabaseModel::getSql() appends the trigger's
            // BEGIN…END body after the table/index/FK DDL in one string — only SqlScriptLexer splits that
            // correctly into individually-executable statements.
            foreach (SqlScriptLexer::splitStatements($databaseModel->getSql()) as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $table = self::table();
        $connection->executeStatement(
            self::currentDriver() === 'pdo_pgsql'
                ? 'DROP TABLE IF EXISTS "' . $table . '" CASCADE'
                : 'DROP TABLE IF EXISTS `' . $table . '`'
        );
    }

    protected function setUp(): void
    {
        if (!self::isEnabled()) {
            $this->markTestSkipped(
                'Integration suite: start tests/Integration/docker-compose.yml and set DDD_TEST_DRIVER=pdo_mysql|pdo_pgsql'
            );
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $quote = self::currentDriver() === 'pdo_pgsql' ? '"' : '`';
        $connection->executeStatement('DELETE FROM ' . $quote . self::table() . $quote);
    }

    private function insert(string $code, bool $active): void
    {
        $dialect = EntityManagerFactory::getDialect();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $activeValue = self::currentDriver() === 'pdo_pgsql' ? ($active ? 'true' : 'false') : ($active ? 1 : 0);
        $connection->executeStatement(
            'INSERT INTO ' . $dialect->quote(self::table())
            . ' (' . $dialect->quote('code') . ', ' . $dialect->quote('active') . ') VALUES (?, ' . $activeValue . ')',
            [$code]
        );
    }

    public function testFirstActiveRowInsertsCleanly(): void
    {
        $this->insert('ABC', true);

        $connection = EntityManagerFactory::getInstance()->getConnection();
        $count = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . EntityManagerFactory::getDialect()->quote(self::table())
        );
        $this->assertSame(1, $count);
    }

    public function testSecondActiveRowWithTheSameCodeIsRefusedWithTheExpectedMessage(): void
    {
        $this->insert('DUP', true);

        try {
            $this->insert('DUP', true);
            $this->fail('Expected the BEFORE INSERT trigger to refuse a second active row with the same code.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('DUPLICATE_CODE', $exception->getMessage());
        }
    }

    public function testInactiveDuplicateCodeIsAllowed(): void
    {
        $this->insert('INACTIVE_OK', true);
        // Does not throw: the trigger only compares against OTHER active rows (NEW.active is false here).
        $this->insert('INACTIVE_OK', false);

        $connection = EntityManagerFactory::getInstance()->getConnection();
        $count = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . EntityManagerFactory::getDialect()->quote(self::table())
            . ' WHERE ' . EntityManagerFactory::getDialect()->quote('code') . ' = ?',
            ['INACTIVE_OK']
        );
        $this->assertSame(2, $count);
    }
}

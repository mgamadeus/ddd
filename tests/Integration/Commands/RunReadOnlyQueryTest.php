<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Commands;

use DDD\Symfony\Commands\Base\Database\RunReadOnlyQuery;
use DDD\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:db:read runs its statement in a READ ONLY transaction: the keyword guard alone let a SELECT that calls a writing
 * stored function write in autocommit, and SELECT … FOR UPDATE take row locks — on the production database the dev
 * workspaces read from.
 */
class RunReadOnlyQueryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = static::connection();
        $connection->executeStatement('INSERT INTO CounterRows (businessKey, amount) VALUES (\'probe\', 1)');
        $connection->executeStatement('DROP FUNCTION IF EXISTS ddd_it_write_counter_row');
        $connection->executeStatement(
            'CREATE FUNCTION ddd_it_write_counter_row() RETURNS INT MODIFIES SQL DATA '
            . 'BEGIN INSERT INTO CounterRows (businessKey) VALUES (UUID()); RETURN 1; END'
        );
    }

    protected function tearDown(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            static::connection()->executeStatement('DROP FUNCTION IF EXISTS ddd_it_write_counter_row');
        }
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} Exit code and output */
    protected function runReadOnlyQuery(string $statement): array
    {
        $commandTester = new CommandTester(new RunReadOnlyQuery());
        $exitCode = $commandTester->execute(['statement' => $statement]);
        return [$exitCode, $commandTester->getDisplay()];
    }

    protected function countCounterRows(): int
    {
        return (int)static::connection()->fetchOne('SELECT COUNT(*) FROM CounterRows');
    }

    protected function assertNoTransactionIsLeftOpen(): void
    {
        $connection = static::connection();
        $this->assertSame(0, $connection->getTransactionNestingLevel());
        $this->assertSame(0, (int)$connection->fetchOne('SELECT @@in_transaction'));
    }

    /** @return array<string, array{0: string}> */
    public static function readStatements(): array
    {
        return [
            'select' => ['SELECT 1 AS one'],
            'with' => ['WITH numbers AS (SELECT 1 AS one) SELECT * FROM numbers'],
            'show' => ['SHOW TABLES'],
        ];
    }

    /** @dataProvider readStatements */
    public function testAReadRunsAndLeavesNoTransactionOpen(string $statement): void
    {
        [$exitCode, $output] = $this->runReadOnlyQuery($statement);

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertNoTransactionIsLeftOpen();
    }

    public function testTheStatementRunsInsideATransaction(): void
    {
        [$exitCode, $output] = $this->runReadOnlyQuery('SELECT @@in_transaction AS inTransaction');

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertSame(1, json_decode($output, true)['rows'][0]['inTransaction']);
    }

    public function testASelectCallingAWritingFunctionIsRefusedAndWritesNothing(): void
    {
        $rowCountBefore = $this->countCounterRows();

        [$exitCode, $output] = $this->runReadOnlyQuery('SELECT ddd_it_write_counter_row()');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('read-only transaction', $output);
        $this->assertSame($rowCountBefore, $this->countCounterRows());
        $this->assertNoTransactionIsLeftOpen();
    }

    public function testSelectForUpdateIsRefused(): void
    {
        // an own LIMIT: the command would otherwise append one behind FOR UPDATE, a syntax error that proves nothing
        [$exitCode, $output] = $this->runReadOnlyQuery("SELECT id FROM CounterRows WHERE businessKey = 'probe' LIMIT 1 FOR UPDATE");

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('read-only transaction', $output);
        $this->assertNoTransactionIsLeftOpen();
    }

    /** @return array<string, array{0: string}> */
    public static function statementsRefusedBeforeTheDatabase(): array
    {
        return [
            'shared lock (MariaDB allows it in a read-only transaction)' => ["SELECT id FROM CounterRows LOCK IN SHARE MODE"],
            'shared lock, MySQL 8 spelling' => ["SELECT id FROM CounterRows FOR SHARE"],
            'temporary table' => ['CREATE TEMPORARY TABLE ddd_it_probe (id INT)'],
            'file write' => ["SELECT 1 INTO OUTFILE '/tmp/ddd_it_probe'"],
        ];
    }

    /** @dataProvider statementsRefusedBeforeTheDatabase */
    public function testAStatementTheGuardRefusesNeverRuns(string $statement): void
    {
        [$exitCode] = $this->runReadOnlyQuery($statement);

        $this->assertSame(Command::INVALID, $exitCode);
    }
}

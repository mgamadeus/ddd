<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Schema;

use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * "Generate database tables" (`EntityModelGeneratorService::createOrUpdateDatabaseTablesForEntities()`, every entity
 * of the project) on both engines: no MariaDB-only statement runs on PostgreSQL, and the PostgreSQL SQL it returns is
 * that dialect's DDL — every framework entity's, applied here in a scratch schema inside a transaction that is rolled
 * back.
 */
class GenerateDatabaseTablesTest extends IntegrationTestCase
{
    public function testGenerateAllReturnsTheDialectsSql(): void
    {
        $entityModelGeneratorService = new EntityModelGeneratorService();

        $sql = $entityModelGeneratorService->createOrUpdateDatabaseTablesForEntities();

        self::assertStringContainsString('IssueGroups', $sql);
        self::assertStringNotContainsString('FOREIGN_KEY_CHECKS', $sql);
        if (static::currentDriver() === 'pdo_pgsql') {
            self::assertStringNotContainsString('`', $sql);
            self::assertStringNotContainsString('ENGINE=', $sql);
        } else {
            self::assertStringContainsString('`IssueGroups`', $sql);
        }
    }

    public function testThePostgresSqlAppliesInAScratchSchema(): void
    {
        if (static::currentDriver() !== 'pdo_pgsql') {
            $this->markTestSkipped('PostgreSQL only (the MariaDB SQL is the unchanged DatabaseModels::getSql())');
        }
        $entityModelGeneratorService = new EntityModelGeneratorService();
        $sql = $entityModelGeneratorService->createOrUpdateDatabaseTablesForEntities();
        $connection = static::connection();
        $scratchSchema = 'ddd_generate_' . bin2hex(random_bytes(4));
        $connection->beginTransaction();
        try {
            $connection->executeStatement('CREATE SCHEMA ' . $scratchSchema);
            $connection->executeStatement('SET LOCAL search_path TO ' . $scratchSchema . ', public');
            foreach (static::splitSqlStatements($sql) as $statement) {
                $connection->executeStatement($statement);
            }
            $tableNames = $connection->fetchFirstColumn(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
                [$scratchSchema]
            );
            self::assertContains('IssueGroups', $tableNames);
            self::assertGreaterThan(10, count($tableNames));
            $uniqueIndexes = $connection->fetchFirstColumn(
                "SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND tablename = 'IssueGroups' AND indexdef LIKE 'CREATE UNIQUE%'",
                [$scratchSchema]
            );
            self::assertNotEmpty(array_filter($uniqueIndexes, static fn(string $indexDefinition): bool => str_contains($indexDefinition, '(environment, "groupHash")')));
        } finally {
            $connection->rollBack();
        }
    }
}

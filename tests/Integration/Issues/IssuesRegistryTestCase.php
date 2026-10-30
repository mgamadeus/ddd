<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatements;
use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssuesJobState;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Base of the issues registry tests: the three registry tables (IssueGroups, Issues, IssuesJobStates) on the current
 * engine, created per class and emptied per test.
 */
abstract class IssuesRegistryTestCase extends IntegrationTestCase
{
    public const array ISSUES_ENTITY_CLASSES = [IssueGroup::class, Issue::class, IssuesJobState::class];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModels = [];
        foreach (self::ISSUES_ENTITY_CLASSES as $issuesEntityClass) {
            $databaseModel = DatabaseModel::fromEntityClass($issuesEntityClass);
            $connection->executeStatement(
                'DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
                . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
            );
            $databaseModels[] = $databaseModel;
        }
        if (static::currentDriver() === 'pdo_pgsql') {
            $statements = [];
            foreach ($databaseModels as $databaseModel) {
                foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel) as $statement) {
                    $statements[] = $statement;
                }
            }
            foreach (DdlStatements::fromStatements(...$statements)->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($databaseModels as $databaseModel) {
            foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
                $connection->executeStatement($statement);
            }
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (array_reverse(self::ISSUES_ENTITY_CLASSES) as $issuesEntityClass) {
                static::connection()->executeStatement(
                    'DROP TABLE IF EXISTS ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($issuesEntityClass)->sqlTableName)
                    . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
                );
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_reverse(self::ISSUES_ENTITY_CLASSES) as $issuesEntityClass) {
            static::connection()->executeStatement(
                'DELETE FROM ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($issuesEntityClass)->sqlTableName)
            );
        }
    }

    protected function findGroup(string $groupHash): ?IssueGroup
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            static::entityManager()->clear();
            /** @var IssueGroupsService $issueGroupsService */
            $issueGroupsService = IssueGroup::getService();
            return $issueGroupsService->findByGroupHash($groupHash);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}

<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Common;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatements;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Accounts\LoginTokens\LoginToken;
use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidation;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Repo\DB\Accounts\LoginTokens\DBLoginToken;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidation;
use DDD\Domain\Common\Services\CronsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The framework's housekeeping queries on both engines — they ran as raw SQL with unquoted mixed-case identifiers,
 * which PostgreSQL folds to lower case (`relation "cronexecutions" does not exist`, `app:crons:execute` down): the
 * cron-execution cleanup, the crons due for execution, the expired login tokens and cache-scope invalidations.
 */
class CleanupQueriesTest extends IntegrationTestCase
{
    public const array HOUSEKEEPING_ENTITY_CLASSES = [Account::class, Cron::class, CronExecution::class, LoginToken::class, CacheScopeInvalidation::class];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModels = array_map(static fn(string $entityClass): DatabaseModel => DatabaseModel::fromEntityClass($entityClass), self::HOUSEKEEPING_ENTITY_CLASSES);
        foreach (array_reverse($databaseModels) as $databaseModel) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
                . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
        }
        if (static::currentDriver() === 'pdo_pgsql') {
            $statements = [];
            foreach ($databaseModels as $databaseModel) {
                foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->getElements() as $statement) {
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
            foreach (array_reverse(self::HOUSEKEEPING_ENTITY_CLASSES) as $entityClass) {
                static::connection()->executeStatement('DROP TABLE IF EXISTS '
                    . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
                    . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['LoginTokens', 'CacheScopeInvalidations', 'CronExecutions', 'Crons', 'Accounts'] as $tableName) {
            static::connection()->executeStatement('DELETE FROM ' . static::quoteIdentifier($tableName));
        }
        static::insertRow('Accounts', ['id' => 1, 'email' => 'a@example.com']);
    }

    protected function secondsFromNow(int $offsetSeconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    /** @return int[] */
    protected function ids(string $tableName): array
    {
        return array_map('intval', static::connection()->fetchFirstColumn(
            'SELECT ' . static::quoteIdentifier('id') . ' FROM ' . static::quoteIdentifier($tableName) . ' ORDER BY 1'
        ));
    }

    protected function insertCron(int $id, bool $isActive, int $nextExecutionOffsetSeconds): void
    {
        static::insertRow('Crons', [
            'id' => $id,
            'name' => 'cron-' . $id,
            'schedule' => '* * * * *',
            'command' => 'app:noop',
            'isActive' => static::currentDriver() === 'pdo_pgsql' ? ($isActive ? 'true' : 'false') : (int)$isActive,
            'nextExecutionScheduledAt' => $this->secondsFromNow($nextExecutionOffsetSeconds),
        ]);
    }

    public function testCronExecutionCleanupAndDueCrons(): void
    {
        $this->insertCron(1, true, -60);    // due
        $this->insertCron(2, false, -60);   // due, inactive
        $this->insertCron(3, true, -60);    // due, but running
        $this->insertCron(4, true, 3600);   // not due
        foreach ([
            [1, 1, CronExecution::STATE_RUNNING, -5 * 3600],   // stuck: deleted
            [2, 1, CronExecution::STATE_ENDED, -15 * 86400],   // old: deleted
            [3, 1, CronExecution::STATE_ENDED, -86400],
            [4, 3, CronExecution::STATE_RUNNING, -600],
        ] as [$id, $cronId, $state, $startedOffset]) {
            static::insertRow('CronExecutions', ['id' => $id, 'cronId' => $cronId, 'state' => $state, 'executionStartedAt' => $this->secondsFromNow($startedOffset)]);
        }
        /** @var CronsService $cronsService */
        $cronsService = DDDService::instance()->getService(CronsService::class);

        $cronsService->cleanupCronExecutions();
        // app:crons:execute runs as the CLI admin account; without one the Cron repository returns nothing
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $dueCrons = $cronsService->getCronsScheduledForExecution();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        self::assertSame([3, 4], $this->ids('CronExecutions'));
        self::assertSame(['cron-1'], array_map(static fn(Cron $cron): string => $cron->name, $dueCrons->getElements()));
    }

    public function testExpiredLoginTokensAndCacheScopeInvalidationsAreDeleted(): void
    {
        static::insertRow('LoginTokens', ['id' => 1, 'accountId' => 1, 'token' => 'used-up', 'usageLimit' => 0, 'validUntil' => $this->secondsFromNow(3600)]);
        static::insertRow('LoginTokens', ['id' => 2, 'accountId' => 1, 'token' => 'expired', 'usageLimit' => 5, 'validUntil' => $this->secondsFromNow(-60)]);
        static::insertRow('LoginTokens', ['id' => 3, 'accountId' => 1, 'token' => 'valid', 'usageLimit' => 5, 'validUntil' => $this->secondsFromNow(3600)]);
        static::insertRow('CacheScopeInvalidations', ['id' => 1, 'cacheScope' => 'used-up', 'numberOfTimesToInvalidateCache' => 0, 'created' => $this->secondsFromNow(0)]);
        static::insertRow('CacheScopeInvalidations', ['id' => 2, 'cacheScope' => 'expired', 'invalidateUntil' => $this->secondsFromNow(-60), 'created' => $this->secondsFromNow(0)]);
        static::insertRow('CacheScopeInvalidations', ['id' => 3, 'cacheScope' => 'old', 'created' => $this->secondsFromNow(-2 * 86400)]);
        static::insertRow('CacheScopeInvalidations', ['id' => 4, 'cacheScope' => 'fresh', 'numberOfTimesToInvalidateCache' => 2, 'created' => $this->secondsFromNow(0)]);

        (new DBLoginToken())->deleteExpiredTokens();
        (new DBCacheScopeInvalidation())->deleteExpiredCacheScopeInvalidations();

        self::assertSame([3], $this->ids('LoginTokens'));
        self::assertSame([4], $this->ids('CacheScopeInvalidations'));
    }

    /** The executor's bookkeeping stamp is a partial write: no validation (an unregistered command), on both engines */
    public function testTheSlotStampIsAPartialWriteThatSkipsTheCommandValidation(): void
    {
        $this->insertCron(7, true, -60);
        static::connection()->executeStatement(
            'UPDATE ' . static::quoteIdentifier('Crons') . ' SET ' . static::quoteIdentifier('command') . " = 'app:removed-command'"
        );
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var CronsService $cronsService */
            $cronsService = DDDService::instance()->getService(CronsService::class);
            $cron = $cronsService->findByName('cron-7');
            $cron->nextExecutionScheduledAt = DateTime::fromTimestamp(strtotime('2030-01-01 00:00:00 UTC'));
            $persistCronColumns = new \ReflectionMethod(Cron::class, 'persistCronColumns');
            $persistCronColumns->invoke($cron, 'nextExecutionScheduledAt');
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        $storedNextExecution = (string)static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('nextExecutionScheduledAt') . ' FROM ' . static::quoteIdentifier('Crons')
            . ' WHERE ' . static::quoteIdentifier('id') . ' = 7'
        );
        self::assertStringStartsWith('2030-01-01', $storedNextExecution);
    }
}

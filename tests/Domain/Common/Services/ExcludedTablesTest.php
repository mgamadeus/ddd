<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModels;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Common\Services\DatabaseSchemaDiffService;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Symfony\Commands\Base\Database\ShowEntitySql;
use DDD\Tests\Domain\Common\Services\Fixtures\ExcludedTableSubclassRow;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows\DBChangeHistoryRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows\DBChangeHistoryRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;

/** `Base.Repo.database.excludedTables`: an application leaves single tables out of its schema */
class ExcludedTablesTest extends TestCase
{
    protected array $configTreeBefore = [];

    protected string $errorLogBefore = '';

    protected function setUp(): void
    {
        $configTreeProperty = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTreeProperty->isInitialized() ? $configTreeProperty->getValue() : [];
        $this->errorLogBefore = (string)ini_get('error_log');
        ini_set('error_log', '/dev/null');
        // an application sets it through LAZYLOAD_DEFAULT_REPO_TYPE; the unit suite has no environment
        if (!isset(LazyLoadRepo::$defaultRepoType)) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configTreeBefore);
        ini_set('error_log', $this->errorLogBefore);
        EntityModelGeneratorService::invalidateCache();
        DatabaseRepoEntity::forgetTableExclusionChecks();
    }

    protected function excludeTablesOf(mixed ...$entityClasses): void
    {
        $configTree = $this->configTreeBefore;
        $configTree['Base']['Repo']['database'] = ['excludedTables' => $entityClasses];
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $configTree);
        EntityModelGeneratorService::invalidateCache();
        DatabaseRepoEntity::forgetTableExclusionChecks();
    }

    /** Puts the two fixture models into the generator's cache of both modes, so no project scan runs */
    protected function provideGeneratorModels(): void
    {
        $databaseModels = new DatabaseModels();
        $changeHistoryRowModel = DatabaseModel::fromEntityClass(ChangeHistoryRow::class);
        $counterRowModel = DatabaseModel::fromEntityClass(CounterRow::class);
        $databaseModels->add($changeHistoryRowModel, $counterRowModel);
        (new ReflectionProperty(EntityModelGeneratorService::class, 'databaseModelsByFilterMode'))
            ->setValue(null, ['filtered' => $databaseModels, 'unfiltered' => $databaseModels]);
    }

    protected function sqlTableNameOf(string $entityClass): string
    {
        return DatabaseModel::getSqlTableNameOfEntityClass($entityClass);
    }

    public function testWithoutTheKeyNothingIsExcluded(): void
    {
        EntityModelGeneratorService::invalidateCache();

        self::assertSame([], EntityModelGeneratorService::getExcludedSqlTableNames());
    }

    public function testAListedEntityExcludesItsTableAndAnUnknownEntryIsIgnored(): void
    {
        $this->excludeTablesOf(ChangeHistoryRow::class, 'App\Domain\NoSuch\Entity', 42);

        self::assertSame([$this->sqlTableNameOf(ChangeHistoryRow::class)], EntityModelGeneratorService::getExcludedSqlTableNames());
        self::assertFalse(EntityModelGeneratorService::isExcludedSqlTableName($this->sqlTableNameOf(CounterRow::class)));
    }

    public function testAnEntryCoversASubclassOnTheSameTable(): void
    {
        $this->excludeTablesOf(ChangeHistoryRow::class);

        self::assertTrue(EntityModelGeneratorService::isExcludedSqlTableName($this->sqlTableNameOf(ExcludedTableSubclassRow::class)));
    }

    public function testTheSchemaPathsLeaveTheTableOutAndModelGenerationKeepsIt(): void
    {
        $this->excludeTablesOf(ChangeHistoryRow::class);
        $databaseModels = new DatabaseModels();
        $changeHistoryRowModel = DatabaseModel::fromEntityClass(ChangeHistoryRow::class);
        $counterRowModel = DatabaseModel::fromEntityClass(CounterRow::class);
        $databaseModels->add($changeHistoryRowModel, $counterRowModel);

        $keptDatabaseModels = EntityModelGeneratorService::filterOutExcludedTables($databaseModels);

        self::assertSame([$counterRowModel->sqlTableName], array_map(
            static fn (DatabaseModel $databaseModel): string => $databaseModel->sqlTableName,
            $keptDatabaseModels->getElements()
        ));
        // the model generator reads the unfiltered models: the excluded entity is still among them
        $this->provideGeneratorModels();
        self::assertCount(1, EntityModelGeneratorService::getDatabaseModels([ChangeHistoryRow::class])->getElements());
    }

    public function testTheSchemaDiffNeitherCreatesNorDropsAnExcludedTable(): void
    {
        $this->excludeTablesOf(ChangeHistoryRow::class);
        $this->provideGeneratorModels();
        $databaseSchemaDiffService = (new ReflectionClass(DatabaseSchemaDiffService::class))->newInstanceWithoutConstructor();
        $getIgnoredLiveTables = new \ReflectionMethod($databaseSchemaDiffService, 'getIgnoredLiveTables');
        $getTargetDatabaseModels = new \ReflectionMethod($databaseSchemaDiffService, 'getTargetDatabaseModels');

        self::assertContains($this->sqlTableNameOf(ChangeHistoryRow::class), $getIgnoredLiveTables->invoke($databaseSchemaDiffService));
        self::assertSame(
            [],
            $getTargetDatabaseModels->invoke($databaseSchemaDiffService, [ChangeHistoryRow::class])->getElements()
        );
        self::assertCount(1, $getTargetDatabaseModels->invoke($databaseSchemaDiffService, [CounterRow::class])->getElements());
    }

    public function testShowEntitySqlLeavesTheTableOutOfTheWholeSchemaButPrintsItOnRequest(): void
    {
        $this->excludeTablesOf(ChangeHistoryRow::class);
        $this->provideGeneratorModels();
        $showEntitySql = new ShowEntitySql();
        $getDatabaseModelsToRender = new \ReflectionMethod($showEntitySql, 'getDatabaseModelsToRender');
        $writeExcludedTableNote = new \ReflectionMethod($showEntitySql, 'writeExcludedTableNote');

        $wholeSchemaSql = $getDatabaseModelsToRender->invoke($showEntitySql, null)->getSql();
        $requestedSql = $getDatabaseModelsToRender->invoke($showEntitySql, [ChangeHistoryRow::class])->getSql();
        $bufferedOutput = new BufferedOutput();
        $writeExcludedTableNote->invoke($showEntitySql, ChangeHistoryRow::class, $bufferedOutput);

        $changeHistoryRowTable = $this->sqlTableNameOf(ChangeHistoryRow::class);
        self::assertStringNotContainsString($changeHistoryRowTable, $wholeSchemaSql);
        self::assertStringContainsString($this->sqlTableNameOf(CounterRow::class), $wholeSchemaSql);
        self::assertStringContainsString($changeHistoryRowTable, $requestedSql);
        self::assertStringContainsString('is excluded by ' . EntityModelGeneratorService::EXCLUDED_TABLES_CONFIG_KEY, $bufferedOutput->fetch());
    }

    public function testAWriteThroughAnExcludedRepositoryIsRefused(): void
    {
        $this->excludeTablesOf(ChangeHistoryRow::class);

        try {
            DBChangeHistoryRow::assertTableIsWritable();
            self::fail('a write to an excluded table must be refused');
        } catch (InternalErrorException $internalErrorException) {
            self::assertStringContainsString(EntityModelGeneratorService::EXCLUDED_TABLES_CONFIG_KEY, $internalErrorException->getMessage());
            self::assertStringContainsString($this->sqlTableNameOf(ChangeHistoryRow::class), $internalErrorException->getMessage());
        }
        DBCounterRow::assertTableIsWritable();

        $this->expectException(InternalErrorException::class);
        $changeHistoryRows = new \DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRows();
        (new DBChangeHistoryRows())->batchUpdate($changeHistoryRows);
    }
}

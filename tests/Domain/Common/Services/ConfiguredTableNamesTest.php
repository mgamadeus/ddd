<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Domain\Common\Services\Fixtures\ExcludedTableSubclassRow;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** `Base.Repo.database.tableNames`: an application names the table of an entity class */
class ConfiguredTableNamesTest extends TestCase
{
    protected array $configTreeBefore = [];

    protected string $errorLogBefore = '';

    protected function setUp(): void
    {
        $configTreeProperty = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTreeProperty->isInitialized() ? $configTreeProperty->getValue() : [];
        $this->errorLogBefore = (string)ini_get('error_log');
        ini_set('error_log', '/dev/null');
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

    protected function configureDatabase(array $databaseConfig): void
    {
        $configTree = $this->configTreeBefore;
        $configTree['Base']['Repo']['database'] = $databaseConfig;
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $configTree);
        EntityModelGeneratorService::invalidateCache();
        DatabaseRepoEntity::forgetTableExclusionChecks();
    }

    public function testWithoutTheKeyTheNameFollowsTheConvention(): void
    {
        $this->configureDatabase([]);

        self::assertNull(EntityModelGeneratorService::findConfiguredSqlTableNameOfEntityClass(ChangeHistoryRow::class));
        self::assertSame('ChangeHistoryRows', DatabaseModel::getSqlTableNameOfEntityClass(ChangeHistoryRow::class));
    }

    public function testAConfiguredNameIsTheTableOfTheEntityItsModelAndASubclassOnTheSameTable(): void
    {
        $this->configureDatabase(['tableNames' => [ChangeHistoryRow::class => 'LegacyHistoryRows']]);

        self::assertSame('LegacyHistoryRows', DatabaseModel::getSqlTableNameOfEntityClass(ChangeHistoryRow::class));
        self::assertSame('LegacyHistoryRows', DatabaseModel::fromEntityClass(ChangeHistoryRow::class)->sqlTableName);
        self::assertSame('LegacyHistoryRows', DatabaseModel::getSqlTableNameOfEntityClass(ExcludedTableSubclassRow::class));
        self::assertSame('CounterRows', DatabaseModel::getSqlTableNameOfEntityClass(CounterRow::class));
    }

    public function testTheEntryOfAPackageEntityCoversTheApplicationsClassOfTheSameName(): void
    {
        // the application's classes live outside the test autoload namespace
        require_once __DIR__ . '/Fixtures/ApplicationOverride/ChangeHistoryRows.php';
        require_once __DIR__ . '/Fixtures/ApplicationOverride/ChangeHistoryRow.php';
        $applicationEntityClass = \App\Tests\Fixtures\ChangeHistoryRows\ChangeHistoryRow::class;
        $this->configureDatabase(['tableNames' => [ChangeHistoryRow::class => 'LegacyHistoryRows']]);

        self::assertSame(\App\Tests\Fixtures\ChangeHistoryRows\ChangeHistoryRows::class, $applicationEntityClass::getEntitySetClass());
        self::assertSame('LegacyHistoryRows', DatabaseModel::getSqlTableNameOfEntityClass($applicationEntityClass));
    }

    public function testAnExclusionFollowsTheConfiguredName(): void
    {
        $this->configureDatabase([
            'tableNames' => [ChangeHistoryRow::class => 'LegacyHistoryRows'],
            'excludedTables' => [ChangeHistoryRow::class],
        ]);

        self::assertSame(['LegacyHistoryRows'], EntityModelGeneratorService::getExcludedSqlTableNames());
    }

    public function testAKeyThatIsNoEntityClassIsIgnored(): void
    {
        $this->configureDatabase(['tableNames' => ['App\Domain\NoSuch\Entity' => 'Anything', 7 => 'Numbered']]);

        self::assertSame('ChangeHistoryRows', DatabaseModel::getSqlTableNameOfEntityClass(ChangeHistoryRow::class));
    }

    public function testAnInvalidTableNameIsRefused(): void
    {
        $this->configureDatabase(['tableNames' => [ChangeHistoryRow::class => 'Legacy Rows; DROP']]);

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Base.Repo.database.tableNames');
        DatabaseModel::getSqlTableNameOfEntityClass(ChangeHistoryRow::class);
    }
}

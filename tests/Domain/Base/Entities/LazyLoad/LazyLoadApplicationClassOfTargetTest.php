<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\ApplicationTargetWithOwnLegacyRepo;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\ApplicationTargetWithoutOwnRepo;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\DBLazyLoadTarget;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LazyLoadSourceWithUndeclaredRepoTypes;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LazyLoadTargetWithDbRepoOnly;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LegacyDBLazyLoadTarget;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A lazy-loaded property declared with a package entity class loads the application's class of that entity (container
 * alias or same path under App\) through the application's repository, when that class declares repositories of its
 * own: a module's `?Account $account` reads the application's account from its legacy table.
 */
class LazyLoadApplicationClassOfTargetTest extends TestCase
{
    protected ?string $defaultRepoTypeBefore;

    protected mixed $containerServiceClassMapBefore;

    protected array $frameworkToAppClassNamesBefore;

    protected function setUp(): void
    {
        $defaultRepoTypeProperty = new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType');
        $this->defaultRepoTypeBefore = $defaultRepoTypeProperty->isInitialized() ? LazyLoadRepo::$defaultRepoType : null;
        $this->containerServiceClassMapBefore = StaticRegistry::$containerServiceClassMap;
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::LEGACY_DB);
        StaticRegistry::$containerServiceClassMap = ['Some\Other\Service' => 'Some\Other\Service'];
        $this->clearLazyLoadRegistries();
    }

    protected function tearDown(): void
    {
        LazyLoadRepo::setDafaultRepoType($this->defaultRepoTypeBefore ?? LazyLoadRepo::DB);
        StaticRegistry::$containerServiceClassMap = $this->containerServiceClassMapBefore;
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        $this->clearLazyLoadRegistries();
    }

    protected function clearLazyLoadRegistries(): void
    {
        foreach (
            [
                LazyLoadSourceWithUndeclaredRepoTypes::class,
                LazyLoadTargetWithDbRepoOnly::class,
                ApplicationTargetWithOwnLegacyRepo::class,
                ApplicationTargetWithoutOwnRepo::class,
            ] as $className
        ) {
            unset(
                StaticRegistry::$propertiesToLazyLoadForClasses[$className],
                StaticRegistry::$repoTypesForClasses[$className],
                StaticRegistry::$defaultRepoTypesForClasses[$className],
                StaticRegistry::$repoClassesForProperties[$className]
            );
        }
    }

    public function testThePackageEntityLoadsThroughTheApplicationsClassAndItsRepository(): void
    {
        StaticRegistry::$frameworkToAppClassNames[LazyLoadTargetWithDbRepoOnly::class] = ApplicationTargetWithOwnLegacyRepo::class;

        $propertiesToLazyLoad = LazyLoadSourceWithUndeclaredRepoTypes::getPropertiesToLazyLoad();

        // the application's class has a LEGACY_DB repo (the application's default): no switch to the package's DB repo
        $this->assertSame([LazyLoadRepo::LEGACY_DB], array_keys($propertiesToLazyLoad['dbOnlyTarget']));
        $this->assertSame(
            LegacyDBLazyLoadTarget::class,
            LazyLoadSourceWithUndeclaredRepoTypes::getRepoClassForProperty(LazyLoadRepo::LEGACY_DB, 'dbOnlyTarget')
        );
        $this->assertSame(
            ApplicationTargetWithOwnLegacyRepo::class,
            DatabaseModel::resolveForeignEntityClassName(LazyLoadTargetWithDbRepoOnly::class, null)
        );
    }

    public function testAnApplicationClassWithoutRepositoriesOfItsOwnLeavesThePackageClass(): void
    {
        StaticRegistry::$frameworkToAppClassNames[LazyLoadTargetWithDbRepoOnly::class] = ApplicationTargetWithoutOwnRepo::class;

        $propertiesToLazyLoad = LazyLoadSourceWithUndeclaredRepoTypes::getPropertiesToLazyLoad();

        $this->assertSame([LazyLoadRepo::DB], array_keys($propertiesToLazyLoad['dbOnlyTarget']));
        $this->assertSame(
            DBLazyLoadTarget::class,
            LazyLoadSourceWithUndeclaredRepoTypes::getRepoClassForProperty(LazyLoadRepo::DB, 'dbOnlyTarget')
        );
        $this->assertSame(
            LazyLoadTargetWithDbRepoOnly::class,
            DatabaseModel::resolveForeignEntityClassName(LazyLoadTargetWithDbRepoOnly::class, null)
        );
    }
}

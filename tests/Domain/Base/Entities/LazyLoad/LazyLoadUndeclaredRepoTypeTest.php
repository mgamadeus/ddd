<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\DBLazyLoadTarget;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LazyLoadSourceWithUndeclaredRepoTypes;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LazyLoadTargetWithDbRepoOnly;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LazyLoadTargetWithLegacyAndDbRepo;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LegacyDBLazyLoadTarget;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A lazy load without a declared repo type in an application whose default repo type (LAZYLOAD_DEFAULT_REPO_TYPE) is
 * LEGACY_DB: a target that has no LEGACY_DB repo is read through its own default repo instead of failing.
 */
class LazyLoadUndeclaredRepoTypeTest extends TestCase
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
        // the default was never read (no environment in unit tests): leave a valid type behind
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
                LazyLoadTargetWithLegacyAndDbRepo::class,
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

    public function testATargetWithoutARepoOfTheDefaultTypeIsReadThroughItsOwnDefaultRepo(): void
    {
        $propertiesToLazyLoad = LazyLoadSourceWithUndeclaredRepoTypes::getPropertiesToLazyLoad();

        $this->assertSame([LazyLoadRepo::DB], array_keys($propertiesToLazyLoad['dbOnlyTarget']));
        $this->assertSame(
            DBLazyLoadTarget::class,
            LazyLoadSourceWithUndeclaredRepoTypes::getRepoClassForProperty(LazyLoadRepo::DB, 'dbOnlyTarget')
        );
    }

    public function testATargetWithARepoOfTheDefaultTypeKeepsTheDefaultType(): void
    {
        $propertiesToLazyLoad = LazyLoadSourceWithUndeclaredRepoTypes::getPropertiesToLazyLoad();

        $this->assertSame([LazyLoadRepo::LEGACY_DB], array_keys($propertiesToLazyLoad['legacyAndDbTarget']));
        $this->assertSame(
            LegacyDBLazyLoadTarget::class,
            LazyLoadSourceWithUndeclaredRepoTypes::getRepoClassForProperty(LazyLoadRepo::LEGACY_DB, 'legacyAndDbTarget')
        );
    }

    public function testADeclaredRepoTypeStaysAsDeclared(): void
    {
        $propertiesToLazyLoad = LazyLoadSourceWithUndeclaredRepoTypes::getPropertiesToLazyLoad();

        $this->assertSame([LazyLoadRepo::LEGACY_DB], array_keys($propertiesToLazyLoad['declaredTarget']));
        $this->assertTrue($propertiesToLazyLoad['declaredTarget'][LazyLoadRepo::LEGACY_DB]->isRepoTypeDeclared);
    }
}

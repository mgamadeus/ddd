<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\AppliedQueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ApplicationQueryOptionsProbe;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ApplicationQueryOptionsProbeUsingTraitAgain;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ModuleQueryOptionsProbe;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ModuleQueryOptionsProbeUsedAgain;
use PHPUnit\Framework\TestCase;

/**
 * The default query options of a module class and of the class the application registered in its place are one:
 * the application's repository reads what module code wrote on the module class.
 */
class ApplicationClassDefaultQueryOptionsTest extends TestCase
{
    protected array $frameworkToAppClassNamesBefore;

    protected function setUp(): void
    {
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[ModuleQueryOptionsProbe::class] = ApplicationQueryOptionsProbe::class;
        StaticRegistry::$frameworkToAppClassNames[ModuleQueryOptionsProbeUsedAgain::class] = ApplicationQueryOptionsProbeUsingTraitAgain::class;
        $this->resetDefaults();
    }

    protected function tearDown(): void
    {
        $this->resetDefaults();
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
    }

    protected function resetDefaults(): void
    {
        ModuleQueryOptionsProbe::resetDefaultQueryOptionsForNewJob();
        ModuleQueryOptionsProbeUsedAgain::resetDefaultQueryOptionsForNewJob();
        ApplicationQueryOptionsProbeUsingTraitAgain::resetDefaultQueryOptionsForNewJob();
    }

    public function testTheModuleClassReadsTheApplicationClassDefaults(): void
    {
        self::assertSame(
            ApplicationQueryOptionsProbe::getDefaultQueryOptions(),
            ModuleQueryOptionsProbe::getDefaultQueryOptions()
        );
    }

    public function testDefaultsSetOnTheModuleClassReachTheApplicationClass(): void
    {
        $queryOptions = new AppliedQueryOptions(new QueryOptions(), ApplicationQueryOptionsProbe::class);
        ModuleQueryOptionsProbe::setDefaultQueryOptions($queryOptions);

        self::assertSame($queryOptions, ApplicationQueryOptionsProbe::getDefaultQueryOptions());
    }

    public function testAnApplicationClassUsingTheTraitAgainSharesTheSlotToo(): void
    {
        $queryOptions = new AppliedQueryOptions(new QueryOptions(), ApplicationQueryOptionsProbeUsingTraitAgain::class);
        ModuleQueryOptionsProbeUsedAgain::setDefaultQueryOptions($queryOptions);

        self::assertSame($queryOptions, ApplicationQueryOptionsProbeUsingTraitAgain::getDefaultQueryOptions());
        self::assertSame($queryOptions, ModuleQueryOptionsProbeUsedAgain::getDefaultQueryOptions());
    }

    public function testASnapshotOnTheModuleClassRestoresTheApplicationClassDefaults(): void
    {
        $topBefore = ApplicationQueryOptionsProbe::getDefaultQueryOptions()->getTop();
        ModuleQueryOptionsProbe::setDefaultQueryOptionsSnapshot();
        try {
            ModuleQueryOptionsProbe::getDefaultQueryOptions()->setTop(7);
            self::assertSame(7, ApplicationQueryOptionsProbe::getDefaultQueryOptions()->getTop());
        } finally {
            ModuleQueryOptionsProbe::restoreDefaultQueryOptionsSnapshot();
        }

        self::assertSame($topBefore, ApplicationQueryOptionsProbe::getDefaultQueryOptions()->getTop());
    }

    public function testAnInheritedTraitDoesNotCount(): void
    {
        // a set inheriting QueryOptionsTrait without repeating it is read without query options: existing lists rely
        // on getting every row, so it does not suddenly get the default top
        self::assertFalse(ReflectionClass::instance(ApplicationQueryOptionsProbe::class)->hasTrait(QueryOptionsTrait::class));
        self::assertTrue(ReflectionClass::instance(ApplicationQueryOptionsProbeUsingTraitAgain::class)->hasTrait(QueryOptionsTrait::class));
    }
}

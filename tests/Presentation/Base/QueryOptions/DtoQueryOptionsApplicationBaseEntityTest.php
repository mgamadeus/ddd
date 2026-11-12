<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\QueryOptions;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ApplicationQueryOptionsProbe;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ModuleQueryOptionsProbe;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ModuleQueryOptionsProbeUsedAgain;
use PHPUnit\Framework\TestCase;

/** A request DTO naming a module class filters, sorts and is documented on the class the application registered. */
class DtoQueryOptionsApplicationBaseEntityTest extends TestCase
{
    protected array $frameworkToAppClassNamesBefore;

    protected function setUp(): void
    {
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[ModuleQueryOptionsProbe::class] = ApplicationQueryOptionsProbe::class;
        StaticRegistry::$frameworkToAppClassNames[ModuleQueryOptionsProbeUsedAgain::class] = ModuleQueryOptionsProbeUsedAgain::class;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
    }

    public function testTheBaseEntityIsTheApplicationClass(): void
    {
        self::assertSame(ApplicationQueryOptionsProbe::class, (new DtoQueryOptions(ModuleQueryOptionsProbe::class))->baseEntity);
    }

    public function testAClassWithoutApplicationClassStaysAsNamed(): void
    {
        self::assertSame(
            ModuleQueryOptionsProbeUsedAgain::class,
            (new DtoQueryOptions(ModuleQueryOptionsProbeUsedAgain::class))->baseEntity
        );
    }
}

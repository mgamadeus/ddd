<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ApplicationQueryOptionsProbe;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ModuleQueryOptionsProbe;
use PHPUnit\Framework\TestCase;

/** A class-wide hide covers the class and its subclasses: an application's subclass hides what its module class hides. */
class InheritedStaticPropertiesToHideTest extends TestCase
{
    protected array $propertiesToHideBefore;

    protected function setUp(): void
    {
        $this->propertiesToHideBefore = StaticRegistry::$propertiesToHideOnSerialization;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$propertiesToHideOnSerialization = $this->propertiesToHideBefore;
    }

    protected function createApplicationObject(): ApplicationQueryOptionsProbe
    {
        $applicationObject = new ApplicationQueryOptionsProbe();
        $applicationObject->name = 'Intake';
        $applicationObject->summary = 'internal summary';
        $applicationObject->agentSpaceKey = 'space-1';
        return $applicationObject;
    }

    public function testAHideOnTheParentClassCoversTheSubclass(): void
    {
        ModuleQueryOptionsProbe::addStaticPropertiesToHide(true, 'summary');

        $serialized = $this->createApplicationObject()->toObject(false, forPersistence: false);

        self::assertArrayNotHasKey('summary', $serialized);
        self::assertSame('Intake', $serialized['name']);
        self::assertSame('space-1', $serialized['agentSpaceKey']);
    }

    public function testAHideOnTheSubclassLeavesTheParentClassVisible(): void
    {
        ApplicationQueryOptionsProbe::addStaticPropertiesToHide(true, 'summary');
        $moduleObject = new ModuleQueryOptionsProbe();
        $moduleObject->summary = 'visible';

        self::assertSame('visible', $moduleObject->toObject(false, forPersistence: false)['summary']);
        self::assertArrayNotHasKey('summary', $this->createApplicationObject()->toObject(false, forPersistence: false));
    }
}

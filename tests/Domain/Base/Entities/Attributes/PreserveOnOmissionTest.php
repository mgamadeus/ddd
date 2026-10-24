<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\Attributes;

use DDD\Domain\Base\Entities\Attributes\PreserveOnOmission;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Infrastructure\Reflection\ReflectionClass;
use PHPUnit\Framework\TestCase;

class PreserveOnOmissionProbe extends Entity
{
    #[PreserveOnOmission]
    public int $displayOrder = 0;

    #[PreserveOnOmission]
    public ?string $status = 'DRAFT';

    public bool $isReservable = true;
}

class PreserveOnOmissionLegacyConstantProbe extends Entity
{
    public const array PRESERVED_ON_OMISSION = ['displayOrder'];

    public int $displayOrder = 0;

    public int $priority = 3;
}

/**
 * A full upsert writes every initialized property, and an inline default initializes it before hydration — so a
 * partial save wrote the default over the stored value. A property marked #[PreserveOnOmission] stays uninitialized
 * when the payload omits it, and its generated model property carries no PHP default; the column keeps its SQL DEFAULT.
 */
class PreserveOnOmissionTest extends TestCase
{
    protected function databaseColumnFor(string $entityClass, string $propertyName): DatabaseColumn
    {
        $reflectionClass = ReflectionClass::instance($entityClass);
        return DatabaseColumn::createFromReflectionProperty($reflectionClass, $reflectionClass->getProperty($propertyName));
    }

    public function testAPreservedPropertyGetsTheSqlDefaultButNoModelDefault(): void
    {
        $displayOrderColumn = $this->databaseColumnFor(PreserveOnOmissionProbe::class, 'displayOrder');
        $this->assertFalse(isset($displayOrderColumn->phpDefaultValue));
        $this->assertSame(0, $displayOrderColumn->sqlDefaultValue);

        $statusColumn = $this->databaseColumnFor(PreserveOnOmissionProbe::class, 'status');
        $this->assertFalse(isset($statusColumn->phpDefaultValue));
        $this->assertSame('DRAFT', $statusColumn->sqlDefaultValue);
    }

    public function testAnOrdinaryPropertyKeepsBothDefaults(): void
    {
        $isReservableColumn = $this->databaseColumnFor(PreserveOnOmissionProbe::class, 'isReservable');
        $this->assertTrue($isReservableColumn->phpDefaultValue);
        $this->assertSame(1, $isReservableColumn->sqlDefaultValue);
    }

    public function testTheLegacyClassConstantIsHonoured(): void
    {
        $this->assertSame(['displayOrder'], PreserveOnOmission::getPreservedPropertyNamesForClass(PreserveOnOmissionLegacyConstantProbe::class));
        $this->assertFalse(isset($this->databaseColumnFor(PreserveOnOmissionLegacyConstantProbe::class, 'displayOrder')->phpDefaultValue));
        $this->assertSame(3, $this->databaseColumnFor(PreserveOnOmissionLegacyConstantProbe::class, 'priority')->phpDefaultValue);
    }

    public function testAnUpdatePayloadThatOmitsThePropertyLeavesItUninitialized(): void
    {
        $payload = (object)['id' => 7, 'isReservable' => false];
        $entity = new PreserveOnOmissionProbe();
        $entity->setPropertiesFromObject($payload);

        $this->assertFalse((new \ReflectionProperty($entity, 'displayOrder'))->isInitialized($entity));
        $this->assertFalse((new \ReflectionProperty($entity, 'status'))->isInitialized($entity));
        // not preserved: the default is still there and will be written, as before
        $this->assertFalse($entity->isReservable);
    }

    public function testAPayloadThatSendsNullOrAValueStillWritesIt(): void
    {
        $payload = (object)['id' => 7, 'displayOrder' => 4, 'status' => null];
        $entity = new PreserveOnOmissionProbe();
        $entity->setPropertiesFromObject($payload);

        $this->assertSame(4, $entity->displayOrder);
        $this->assertNull($entity->status);
    }

    public function testACreateKeepsItsDefaults(): void
    {
        $payload = (object)['isReservable' => false];
        $entity = new PreserveOnOmissionProbe();
        $entity->setPropertiesFromObject($payload);

        $this->assertSame(0, $entity->displayOrder);
        $this->assertSame('DRAFT', $entity->status);
    }
}

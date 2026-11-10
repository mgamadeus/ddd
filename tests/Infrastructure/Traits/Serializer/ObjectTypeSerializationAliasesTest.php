<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** A class that "moved": it writes its former name */
class MovedAliasProbe extends ValueObject
{
    public string $label = 'moved';
}

/** A sibling type that did not move */
class StayingAliasProbe extends ValueObject
{
    public string $label = 'staying';
}

/** An abstract base: only the stored objectType names the concrete subclass */
abstract class AbstractAliasProbe extends ValueObject
{
    public string $label = '';
}

/** A moved subclass of the abstract base: it writes its former name */
class MovedAbstractAliasProbe extends AbstractAliasProbe
{
}

/** A set whose element type is the abstract base */
/**
 * @property AbstractAliasProbe[] $elements
 * @method AbstractAliasProbe[] getElements()
 */
class AbstractAliasProbes extends \DDD\Domain\Base\Entities\ObjectSet
{
}

/** Properties of ONE allowed type that is abstract: a single child, an array of children, a set */
class AbstractAliasProbeContainer extends ValueObject
{
    public ?AbstractAliasProbe $abstractChild = null;

    /** @var AbstractAliasProbe[] */
    public array $abstractChildren = [];

    public ?AbstractAliasProbes $abstractChildSet = null;
}

/** A polymorphic property: reading it back needs the objectType */
class AliasProbeContainer extends ValueObject
{
    public MovedAliasProbe|StayingAliasProbe $polymorphicChild;

    public ?MovedAliasProbe $singleTypeChild = null;
}

class ObjectTypeSerializationAliasesTest extends TestCase
{
    /** The name the moved class had before it moved — a class that no longer exists */
    public const string FORMER_CLASS_NAME = 'App\\Former\\Namespace\\MovedAliasProbe';

    /** The former name of the moved subclass of the abstract base */
    public const string FORMER_ABSTRACT_SUBCLASS_NAME = 'App\\Former\\Namespace\\MovedAbstractAliasProbe';

    protected function setUp(): void
    {
        $this->setObjectTypeConfig(
            [MovedAliasProbe::class => self::FORMER_CLASS_NAME, MovedAbstractAliasProbe::class => self::FORMER_ABSTRACT_SUBCLASS_NAME],
            [self::FORMER_CLASS_NAME => MovedAliasProbe::class, self::FORMER_ABSTRACT_SUBCLASS_NAME => MovedAbstractAliasProbe::class]
        );
    }

    protected function tearDown(): void
    {
        ReflectionClass::clearObjectTypeConfigCaches();
    }

    /**
     * Installs both maps the way the config would deliver them.
     *
     * @param array<string, string> $serializationAliases class => objectType written
     * @param array<string, string> $migrations objectType read => class
     */
    protected function setObjectTypeConfig(array $serializationAliases, array $migrations): void
    {
        (new ReflectionProperty(ReflectionClass::class, 'objectTypeSerializationAliases'))->setValue(null, $serializationAliases);
        (new ReflectionProperty(ReflectionClass::class, 'objectTypeMigrations'))->setValue(null, $migrations);
    }

    public function testAnAliasedClassWritesItsFormerNameAtEveryDepth(): void
    {
        $container = new AliasProbeContainer();
        $container->polymorphicChild = new MovedAliasProbe();
        $container->singleTypeChild = new MovedAliasProbe();

        $serialized = $container->toObject(cached: false);

        $this->assertSame(AliasProbeContainer::class, $serialized['objectType']);
        $this->assertSame(self::FORMER_CLASS_NAME, $serialized['polymorphicChild']['objectType']);
        $this->assertSame(self::FORMER_CLASS_NAME, $serialized['singleTypeChild']['objectType']);
        $this->assertSame(self::FORMER_CLASS_NAME, (new MovedAliasProbe())->toObject(cached: false)['objectType']);
    }

    public function testAClassWithoutAnAliasWritesItsOwnName(): void
    {
        $container = new AliasProbeContainer();
        $container->polymorphicChild = new StayingAliasProbe();

        $serialized = $container->toObject(cached: false, forPersistence: false);

        $this->assertSame(StayingAliasProbe::class, $serialized['polymorphicChild']['objectType']);
    }

    /** What the new code wrote reads back into the moved class through the forward migration map. */
    public function testTheFormerNameReadsBackIntoTheMovedClass(): void
    {
        $container = new AliasProbeContainer();
        $container->polymorphicChild = new MovedAliasProbe();
        $container->polymorphicChild->label = 'written by the new code';
        $storedForm = json_decode(json_encode($container->toObject(cached: false)));

        $readBack = new AliasProbeContainer();
        $readBack->setPropertiesFromObject($storedForm);

        $this->assertInstanceOf(MovedAliasProbe::class, $readBack->polymorphicChild);
        $this->assertSame('written by the new code', $readBack->polymorphicChild->label);
        $this->assertSame(MovedAliasProbe::class, $readBack->polymorphicChild->objectType);
    }

    /**
     * A property of ONE abstract type (single, array item, set element): the moved subclass written under its former
     * name reads back into the moved class through the migration map — without it the serializer would ignore the
     * unknown name and fall back to the abstract declared type, which cannot be instantiated.
     */
    public function testTheFormerNameOfASubclassReadsBackIntoAPropertyOfOneAbstractType(): void
    {
        $container = new AbstractAliasProbeContainer();
        $container->abstractChild = new MovedAbstractAliasProbe();
        $container->abstractChild->label = 'single';
        $arrayChild = new MovedAbstractAliasProbe();
        $arrayChild->label = 'array item';
        $container->abstractChildren = [$arrayChild];
        $setChild = new MovedAbstractAliasProbe();
        $setChild->label = 'set element';
        $container->abstractChildSet = new AbstractAliasProbes();
        $container->abstractChildSet->add($setChild);
        $storedJson = json_encode($container->toObject(cached: false));
        $this->assertSame(3, substr_count($storedJson, json_encode(self::FORMER_ABSTRACT_SUBCLASS_NAME)));
        $storedForm = json_decode($storedJson);

        $readBack = new AbstractAliasProbeContainer();
        $readBack->setPropertiesFromObject($storedForm);

        $this->assertInstanceOf(MovedAbstractAliasProbe::class, $readBack->abstractChild);
        $this->assertSame('single', $readBack->abstractChild->label);
        $this->assertInstanceOf(MovedAbstractAliasProbe::class, $readBack->abstractChildren[0]);
        $this->assertSame('array item', $readBack->abstractChildren[0]->label);
        $this->assertInstanceOf(MovedAbstractAliasProbe::class, $readBack->abstractChildSet->first());
        $this->assertSame('set element', $readBack->abstractChildSet->first()->label);
    }

    public function testWithoutConfigNothingChanges(): void
    {
        $this->setObjectTypeConfig([], []);

        $this->assertSame(MovedAliasProbe::class, (new MovedAliasProbe())->toObject(cached: false)['objectType']);
    }
}

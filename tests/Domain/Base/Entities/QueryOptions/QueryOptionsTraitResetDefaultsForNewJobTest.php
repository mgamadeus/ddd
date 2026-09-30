<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class FirstQueryOptionsComposingProbe
{
    use QueryOptionsTrait;
}

class SecondQueryOptionsComposingProbe
{
    use QueryOptionsTrait;
}

/** A class that merely INHERITS the trait shares its parent's copy of the statics — the documented gotcha. */
class InheritingQueryOptionsProbe extends FirstQueryOptionsComposingProbe
{
}

/**
 * The trait's default query options live in a TRAIT static, so every composing class holds its own copy. The reset
 * therefore has to run on each composing class — which is why the docblock says so and this test proves it.
 */
class QueryOptionsTraitResetDefaultsForNewJobTest extends TestCase
{
    protected function setUp(): void
    {
        FirstQueryOptionsComposingProbe::resetDefaultQueryOptionsForNewJob();
        SecondQueryOptionsComposingProbe::resetDefaultQueryOptionsForNewJob();
    }

    protected function traitStatic(string $className, string $propertyName): mixed
    {
        return (new ReflectionProperty($className, $propertyName))->getValue();
    }

    protected function seedMutatedDefaults(string $className): void
    {
        (new ReflectionProperty($className, 'defaultQueryOptions'))
            ->setValue(null, [$className => 'the previous job\'s mutated defaults']);
        (new ReflectionProperty($className, 'defaultQueryOptionsSnapshotStack'))
            ->setValue(null, [$className => ['an unrestored snapshot']]);
    }

    public function testTheMutatedDefaultsAndUnrestoredSnapshotsOfThePreviousJobAreDropped(): void
    {
        $this->seedMutatedDefaults(FirstQueryOptionsComposingProbe::class);

        FirstQueryOptionsComposingProbe::resetDefaultQueryOptionsForNewJob();

        $this->assertSame([], $this->traitStatic(FirstQueryOptionsComposingProbe::class, 'defaultQueryOptions'));
        $this->assertSame(
            [],
            $this->traitStatic(FirstQueryOptionsComposingProbe::class, 'defaultQueryOptionsSnapshotStack')
        );
    }

    /** One composing class's reset leaves another composing class's copy untouched: the handler base must iterate. */
    public function testResettingOneComposingClassDoesNotResetAnother(): void
    {
        $this->seedMutatedDefaults(FirstQueryOptionsComposingProbe::class);
        $this->seedMutatedDefaults(SecondQueryOptionsComposingProbe::class);

        FirstQueryOptionsComposingProbe::resetDefaultQueryOptionsForNewJob();

        $this->assertSame([], $this->traitStatic(FirstQueryOptionsComposingProbe::class, 'defaultQueryOptions'));
        $this->assertNotSame(
            [],
            $this->traitStatic(SecondQueryOptionsComposingProbe::class, 'defaultQueryOptions'),
            'each composing class holds its own copy of the trait static'
        );
    }

    /** An inheriting class shares the parent's copy, so resetting on either clears both. */
    public function testAnInheritingClassSharesTheParentsCopy(): void
    {
        $this->seedMutatedDefaults(FirstQueryOptionsComposingProbe::class);

        InheritingQueryOptionsProbe::resetDefaultQueryOptionsForNewJob();

        $this->assertSame([], $this->traitStatic(FirstQueryOptionsComposingProbe::class, 'defaultQueryOptions'));
    }
}

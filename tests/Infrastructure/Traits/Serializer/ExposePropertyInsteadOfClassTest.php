<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Components\Schema;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\ExposedGappedNumberSeries;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\ExposedNumberSeries;
use DDD\Tests\Infrastructure\Traits\Serializer\Fixtures\ExposedNumberSeriesHolder;
use PHPUnit\Framework\TestCase;

/**
 * A class with #[ExposePropertyInsteadOfClass] is that property on the wire: serialized as its value (an empty list
 * stays []), read back from it, and documented with its schema.
 */
class ExposePropertyInsteadOfClassTest extends TestCase
{
    public function testTheClassSerializesAsTheExposedList(): void
    {
        $holder = new ExposedNumberSeriesHolder();
        $holder->samples = new ExposedNumberSeries();
        $holder->samples->values = [1.5, 2.5];

        $this->assertSame([1.5, 2.5], $holder->toObject(false, forPersistence: false)['samples']);
    }

    public function testAnEmptyListStaysAnEmptyList(): void
    {
        $holder = new ExposedNumberSeriesHolder();
        $holder->samples = new ExposedNumberSeries();

        $this->assertSame('{"samples":[],"seriesList":[]}', json_encode(
            array_diff_key($holder->toObject(false, forPersistence: false), ['objectType' => true])
        ));
    }

    public function testTheClassIsReadBackFromTheExposedList(): void
    {
        $payload = json_decode('{"samples":[1.5,2.5],"optionalSamples":[]}');
        $holder = new ExposedNumberSeriesHolder();
        $holder->setPropertiesFromObject($payload);

        $this->assertSame([1.5, 2.5], $holder->samples->values);
        $this->assertSame([], $holder->optionalSamples->values);
    }

    public function testANullEntryOfAListWithNullableElementsKeepsItsPositionInBothDirections(): void
    {
        $holder = new ExposedNumberSeriesHolder();
        $holder->gappedSamples = new ExposedGappedNumberSeries();
        $holder->gappedSamples->values = [12.5, null, 12.9];
        $this->assertSame([12.5, null, 12.9], $holder->toObject(false, forPersistence: false)['gappedSamples']);
        $this->assertSame([12.5, null, 12.9], $holder->toObject(false, forPersistence: true)['gappedSamples']);

        $payload = json_decode('{"gappedSamples":[12.5,null,12.9]}');
        $readHolder = new ExposedNumberSeriesHolder();
        $readHolder->setPropertiesFromObject($payload);
        $this->assertSame([12.5, null, 12.9], $readHolder->gappedSamples->values);
    }

    public function testAListWithoutNullableElementsRefusesANullEntry(): void
    {
        $payload = json_decode('{"samples":[12.5,null,12.9]}');
        $readHolder = new ExposedNumberSeriesHolder();
        $this->expectException(BadRequestException::class);
        $readHolder->setPropertiesFromObject($payload);
    }

    public function testTheSchemaOfAListWithNullableElementsMarksItsItemsNullable(): void
    {
        $classWithNamespace = new ClassWithNamespace(ExposedNumberSeriesHolder::class);
        $schema = new Schema($classWithNamespace, Parameter::RESPONSE);
        $schema->buildSchema();

        $gappedSamples = $schema->properties['gappedSamples'];
        $this->assertSame('array', $gappedSamples->type);
        $this->assertSame(['type' => 'number', 'nullable' => true], $gappedSamples->items);
        $this->assertSame(['type' => 'number'], $schema->properties['samples']->items);
    }

    public function testTheSchemaIsTheExposedPropertys(): void
    {
        $classWithNamespace = new ClassWithNamespace(ExposedNumberSeriesHolder::class);
        $schema = new Schema($classWithNamespace, Parameter::RESPONSE);
        $schema->buildSchema();

        $samples = $schema->properties['samples'];
        $this->assertSame('array', $samples->type);
        $this->assertSame(['type' => 'number'], $samples->items);
        $this->assertNull($samples->ref);
        $this->assertSame('Samples of a measurement', $samples->description);

        $optionalSamples = $schema->properties['optionalSamples'];
        $this->assertSame('array', $optionalSamples->type);
        $this->assertTrue($optionalSamples->nullable);

        $seriesList = $schema->properties['seriesList'];
        $this->assertSame('array', $seriesList->type);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'number']], $seriesList->items);
    }
}

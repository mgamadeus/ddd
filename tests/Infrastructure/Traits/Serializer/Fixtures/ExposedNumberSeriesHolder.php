<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;

/** Holds exposed number series as a property, nullable, and as a list */
class ExposedNumberSeriesHolder extends ValueObject
{
    /** @var ExposedNumberSeries Samples of a measurement */
    public ExposedNumberSeries $samples;

    /** @var ExposedNumberSeries|null Optional samples */
    public ?ExposedNumberSeries $optionalSamples = null;

    /** @var ExposedNumberSeries[] Several series */
    public array $seriesList = [];
}

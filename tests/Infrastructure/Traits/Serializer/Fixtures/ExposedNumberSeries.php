<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\ExposePropertyInsteadOfClass;

/** A value object that is a plain list of numbers on the wire */
#[ExposePropertyInsteadOfClass('values')]
class ExposedNumberSeries extends ValueObject
{
    /** @var float[] The numbers */
    public array $values = [];
}

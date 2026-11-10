<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Traits\Serializer\Fixtures;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\ExposePropertyInsteadOfClass;

/**
 * A series whose samples may be missing: null is an entry of the list and keeps its position.
 */
#[ExposePropertyInsteadOfClass('values')]
class ExposedGappedNumberSeries extends ValueObject
{
    /** @var (float|null)[] Samples, null where none was measured */
    public array $values = [];
}

<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * JSON value object of the fixtures.
 */
class FixtureSettings extends ValueObject
{
    /** @var string|null */
    public ?string $color;

    /** @var int|null */
    public ?int $size;
}

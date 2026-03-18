<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/**
 * Copies the serialized value of the property to additional alias names in the output.
 * This is useful for backward compatibility when a property is renamed but old API consumers
 * still expect the old property name.
 *
 * Usage: #[Aliases('oldPropertyName', 'anotherAlias')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Aliases extends SerializerAttribute
{
    use BaseAttributeTrait;

    /** @var string[] The alias property names to copy the value to */
    public array $aliases = [];

    public function __construct(string ...$aliases)
    {
        $this->aliases = $aliases;
    }
}

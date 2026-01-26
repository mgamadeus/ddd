<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/**
 * Allows for Activation of Token-Oriented Object Notation (TOON) – Compact, human-readable
 * https://github.com/toon-format/toon
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class SerializeInToonFormat extends SerializerAttribute
{
    use BaseAttributeTrait;

    public const TOON_PROPERTY_POSTFIX = 'InToonFormat';

    /**
     * Retrieves the toon property name by appending a predefined postfix.
     * @param string $propertyName The base name of the property.
     * @return string The concatenated property name with the postfix.
     */
    public static function getToonPropertyName(string $propertyName): string
    {
        return $propertyName . self::TOON_PROPERTY_POSTFIX;
    }
}
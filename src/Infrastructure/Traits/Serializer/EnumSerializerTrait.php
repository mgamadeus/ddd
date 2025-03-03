<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer;

use InvalidArgumentException;
use ReflectionEnum;

trait EnumSerializerTrait
{
    /**
     * Check if this is a backed enum
     *
     * @return bool
     */
    protected static function isBacked(): bool
    {
        return (new ReflectionEnum(static::class))->isBacked();
    }

    /**
     * Create from JSON representation
     *
     * @param string $json
     *
     * @return static
     */
    public static function fromJson(string $json): static
    {
        if (static::isBacked()) {
            $reflection = new ReflectionEnum(static::class);
            $backingType = $reflection->getBackingType()?->getName();

            if ($backingType === 'int') {
                return self::fromString((int)$json);
            }
        }

        return self::fromString($json);
    }

    /**
     * Convert a value to the corresponding enum case
     * This implementation is generic and works for all enums
     *
     * @param int|string $value
     * @return static
     */
    public static function fromString(int|string $value): static
    {
        if (!static::isBacked() && is_string($value)) {
            // For non-backed enums, try to match by case name
            foreach (static::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }
        }

        $case = self::tryFrom($value);

        if ($case === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid value %s for enum %s',
                    $value,
                    static::class,
                ),
            );
        }

        return $case;
    }

    /**
     * Create from serialized value
     *
     * @param int|string $value
     *
     * @return static
     */
    public static function deserialize(int|string $value): static
    {
        return static::fromString($value);
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return static::isBacked() ? (string)$this->value : $this->name;
    }

    /**
     * @return int|string
     */
    public function getValue(): int|string
    {
        return static::isBacked() ? $this->value : $this->name;
    }

    /**
     * JSON serialization for enums
     *
     * @return int|string
     */
    public function jsonSerialize(): int|string
    {
        return static::isBacked() ? $this->value : $this->name;
    }
}

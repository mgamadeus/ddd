<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer;

/**
 * Serializer flag constants for use with SerializerTrait::toObject()
 *
 * Flags can be combined using bitwise OR operator if more flags are added in the future
 */
class Serializer
{
    /**
     * When serializing ObjectSets, serialize elements as array instead of keeping them in 'elements' property
     */
    public const int SERIALIZE_ELEMENTS_AS_ARRAY_IN_OBJECT_SETS = 1 << 0; // 1

    /**
     * toObject(): render DateTime (never Date) properties in SerializerRegistry::$modelFacingTimezone with their
     * offset. Needs the flag AND a non-null zone, so persistence and REST output can never enter that branch.
     */
    public const int MODEL_FACING_DATETIME = 1 << 1; // 2

    /**
     * Check if a specific flag is set
     */
    public static function hasFlag(int $flags, int $flag): bool
    {
        return ($flags & $flag) === $flag;
    }

    /**
     * Add a flag to the flags value
     */
    public static function addFlag(int $flags, int $flag): int
    {
        return $flags | $flag;
    }

    /**
     * Remove a flag from the flags value
     */
    public static function removeFlag(int $flags, int $flag): int
    {
        return $flags & ~$flag;
    }

    /**
     * Toggle a flag in the flags value
     */
    public static function toggleFlag(int $flags, int $flag): int
    {
        return $flags ^ $flag;
    }
}
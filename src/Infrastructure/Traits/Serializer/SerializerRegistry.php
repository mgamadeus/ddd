<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DateTimeZone;

class SerializerRegistry
{
    public const int MAX_SIZE = 3000;

    /**
     * @var DateTimeZone|null The zone a supplied date-time STRING is written in. Consulted only by the fromString
     * hydration branches and only for DateTime::class exactly. Null (the default, and every REST / persistence /
     * entity-load path) leaves hydration byte-for-byte as before.
     */
    public static ?DateTimeZone $inputTimezone = null;

    /**
     * @var DateTimeZone|null The zone DateTime properties are rendered in. Consulted only by toObject() under
     * Serializer::MODEL_FACING_DATETIME. Null leaves serialization byte-for-byte as before.
     */
    public static ?DateTimeZone $modelFacingTimezone = null;

    public static $marks = [];

    /** @var array Static cache for toObject call */
    public static $toOjectCache = [];

    /** @var array Static cache for setPropertiesFromObjectCall call */
    public static $setPropertiesFromObjectCache = [];

    /**
     * Runs $operation with $timezone as the hydration zone and restores the previous value afterwards, so nesting
     * and exceptions leave no zone behind.
     * @param DateTimeZone|null $timezone
     * @param callable $operation
     * @return mixed
     */
    public static function withInputTimezone(?DateTimeZone $timezone, callable $operation): mixed
    {
        $previousTimezone = self::$inputTimezone;
        self::$inputTimezone = $timezone;
        try {
            return $operation();
        } finally {
            self::$inputTimezone = $previousTimezone;
        }
    }

    /**
     * Runs $operation with $timezone as the model-facing presentation zone and restores the previous value
     * afterwards.
     * @param DateTimeZone|null $timezone
     * @param callable $operation
     * @return mixed
     */
    public static function withModelFacingTimezone(?DateTimeZone $timezone, callable $operation): mixed
    {
        $previousTimezone = self::$modelFacingTimezone;
        self::$modelFacingTimezone = $timezone;
        try {
            return $operation();
        } finally {
            self::$modelFacingTimezone = $previousTimezone;
        }
    }

    /**
     * Shared by both hydration sites: parses one supplied string into the declared class. Only DateTime::class
     * exactly is zone-aware — Date and every other subclass keep their own fromString().
     * @param string $typeToInstance
     * @param string $value
     * @return mixed false when no format matched
     */
    public static function hydrateFromString(string $typeToInstance, string $value): mixed
    {
        if ($typeToInstance === DateTime::class && self::$inputTimezone !== null) {
            return DateTime::fromStringInZone($value, self::$inputTimezone);
        }
        return $typeToInstance::fromString($value);
    }

    /**
     * returns statically cached entity by class name and id in order to avoid executing setPropertiesFromObject
     * @param string $entityClass
     * @param string|int $entityId
     * @return Entity|null
     */
    public static function getInstanceForSetPropertiesFromObjectCache(
        string $entityClass,
        string|int $entityId
    ): ?Entity {
        return self::$setPropertiesFromObjectCache[$entityClass][$entityId] ?? null;
    }

    /**
     * stores statically an entity by class name and id in order to avoid executing setPropertiesFromObject
     * multiple times
     * @param Entity $entity
     * @return void
     */
    public static function setInstanceForSetPropertiesFromObjectCache(DefaultObject &$entity): void
    {
        if (!isset(self::$setPropertiesFromObjectCache[$entity::class])) {
            self::$setPropertiesFromObjectCache[$entity::class] = [];
        }
        self::$setPropertiesFromObjectCache[$entity::class][$entity->id] = $entity;
    }

    /**
     * clears static cache for SetPropertiesFromObject
     * @return void
     */
    public static function clearSetPropertiesFromObjectCache(): void
    {
        self::$setPropertiesFromObjectCache = [];
    }

    /**
     * Retrieves Object / array by spl_object_id
     * @param string|int $objectId
     * @return object|array|null
     */
    public static function getToObjectCacheForObjectId(string|int $objectId): object|array|null
    {
        if (isset(self::$toOjectCache[$objectId])) {
            return self::$toOjectCache[$objectId];
        }
        return null;
    }

    /**
     * @return void Empties toObjectCache
     */
    public static function clearToObjectCache(): void
    {
        self::$toOjectCache = [];
    }

    /**
     * Stores object / array into static cache by spl_object_id
     * When memory usage is high, storing is skipped
     * @param string|int $objectId
     * @param object|array $object
     * @return void
     */
    public static function setToObjectCacheForObjectId(string|int $objectId, object|array &$object): void
    {
        // if memory usage is too high, we do not store new objects
        if (DDDService::instance()->isMemoryUsageHigh()) {
            return;
        }
        self::$toOjectCache[$objectId] = $object;
    }
}
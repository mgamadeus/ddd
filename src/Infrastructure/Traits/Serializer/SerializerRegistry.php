<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer;

use DDD\Infrastructure\Services\AppService;
use DDD\Domain\Base\Entities\Entity;

class SerializerRegistry
{
    public const MAX_SIZE = 3000;
    public static $marks = [];

    /** @var array Static cache for toObject call */
    public static $toOjectCache = [];

    /** @var array Static cache for setPropertiesFromObjectCall call */
    public static $setPropertiesFromObjectCache = [];

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
    public static function setInstanceForSetPropertiesFromObjectCache(Entity &$entity): void
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
    public static function clearToObjectCache():void {
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
        if (AppService::instance()->isMemoryUsageHigh()){
            return;
        }
        self::$toOjectCache[$objectId] = $object;
    }
}
<?php

namespace DDD\Domain\Base\Entities\Traits;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Arr;
use DDD\Infrastructure\Traits\Serializer\SerializerRegistry;
use ReflectionException;

trait ValueObjectTrait
{
    use DefaultObjectTrait;

    public const bool IS_VALUE_OBJECT = true;

    public function equals(?BaseObject &$other = null): bool
    {
        if (!$other) {
            return false;
        }
        if (!($other instanceof ValueObject)) {
            return false;
        }
        return $this->uniqueKey() == $other->uniqueKey();
    }

    /**
     * @return mixed This method transforms the data to a persistence format. By default JSON is used
     * but in some cases a special format can make sense
     */
    public function mapToRepository(): mixed
    {
        return $this->toObject(ignoreHideAttributes: true, cached: false, forPersistence: true);
    }

    /**
     * This is the inverse method to mapToRepository, the object populates itself with the data from the repo
     * @param mixed $repoObject
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function mapFromRepository(mixed $repoObject): void
    {
        // Same reason as DBEntity::mapToEntity(): a persisted date-time must never be read through an input zone.
        if (SerializerRegistry::$inputTimezone !== null) {
            throw new InternalErrorException(
                static::class . ': value object hydration from the repository while SerializerRegistry::$inputTimezone'
                . ' is set (' . SerializerRegistry::$inputTimezone->getName() . '): stored date-times would be'
                . ' re-read as local wall-clock time. Load entities outside the withInputTimezone() region.'
            );
        }
        // NULL-safe: an absent repo value (e.g. a NULL JSON column hydrating an optional VO property) maps to
        // NOTHING — before this guard $repoObjectProcessed stayed undefined and setPropertiesFromObject(null)
        // fataled with a TypeError on every row whose optional JSON column is NULL.
        if ($repoObject === null) {
            return;
        }
        if (is_string($repoObject)) {
            $repoObjectProcessed = json_decode($repoObject);
        } elseif (is_array($repoObject)) {
            $repoObjectProcessed = Arr::toObject($repoObject);
        } elseif (is_object($repoObject)) {
            $repoObjectProcessed = $repoObject;
        } else {
            return; // scalar noise (bool/int) — nothing a VO could map
        }
        // json_decode of "null"/"" and empty-array conversion produce non-objects — same absent-value semantics.
        if (!is_object($repoObjectProcessed)) {
            return;
        }
        $this->setPropertiesFromObject($repoObjectProcessed, false);
    }
}
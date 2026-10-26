<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\Attributes\PreserveOnOmission;
use DDD\Domain\Base\Entities\Traits\EntityTrait;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use ReflectionException;

class Entity extends DefaultObject
{
    use EntityTrait;

    /**
     * Hydrates from $object, then unsets each {@see PreserveOnOmission} property the payload did not mention.
     *
     * @param object $object The decoded payload, still carrying which keys the caller actually sent
     * @param bool $throwErrors
     * @param bool $rootCall
     * @param bool $sanitizeInput
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertiesFromObject(
        object &$object,
        $throwErrors = true,
        bool $rootCall = true,
        bool $sanitizeInput = false
    ): void {
        parent::setPropertiesFromObject($object, $throwErrors, $rootCall, $sanitizeInput);
        $this->unsetPropertiesOmittedFromPayload($object);
    }

    /**
     * Leaves each property preserved on omission uninitialized when $object has no such key — the one state in which
     * the upsert leaves its column alone. A key present with null stays null: that is a deliberate clear.
     * `property_exists()`, not `isset()`, because isset() cannot tell an absent key from a null one.
     *
     * @param object $object The payload the entity was hydrated from
     * @return void
     * @throws ReflectionException
     */
    protected function unsetPropertiesOmittedFromPayload(object $object): void
    {
        // without an id this is a create: nothing is stored yet, and the defaults must be written
        if (!isset($this->id) || !$this->id) {
            return;
        }
        foreach (PreserveOnOmission::getPreservedPropertyNamesForClass(static::class) as $propertyName) {
            if (!property_exists($object, $propertyName)) {
                unset($this->$propertyName);
            }
        }
    }
}

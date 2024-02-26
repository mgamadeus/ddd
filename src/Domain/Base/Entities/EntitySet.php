<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Services\AppService;
use DDD\Infrastructure\Services\Service;
use Doctrine\Inflector\InflectorFactory;

/**
 * @method Entity first()
 * @method Entity getByUniqueKey(string $uniqueKey)
 * @method Entity[] getElements()
 * @property Entity[] $elements;
 */
class EntitySet extends ObjectSet
{
    public const SERVICE_NAME = null;

    /**
     * returns the class of the corresponding Entity if existent
     * uses configured number if present in static::$entityClass otherwise it uses inflector to singularize
     * @return string
     */
    public static function getEntityClass(): string
    {
        $currentClassName = AppService::instance()->getContainerServiceClassNameForClass(static::class);
        if (isset(StaticRegistry::$entityClasses[$currentClassName])) {
            return StaticRegistry::$entityClasses[$currentClassName];
        }
        /** @var Entity $currentClassName */
        $classWithNamesapce = $currentClassName::getClassWithNamespace();
        $inflector = InflectorFactory::create()->build();
        $singularClassName = $inflector->singularize($classWithNamesapce->name);
        $classWithNamesapceSingular = new ClassWithNamespace($singularClassName, $classWithNamesapce->namespace);
        if (class_exists($classWithNamesapceSingular->getNameWithNamespace())) {
            StaticRegistry::$entityClasses[$currentClassName] = $classWithNamesapceSingular->getNameWithNamespace();
        }
        return StaticRegistry::$entityClasses[$currentClassName];
    }

    /**
     * Returns all ids of entities in set as array
     * @return array
     */
    public function getEntityIds(): array
    {
        $entityIds = [];
        foreach ($this->getElements() as $element) {
            $entityIds[] = $element->id;
        }
        return $entityIds;
    }

    /**
     * @return Entity|null Persists all Entities from Set
     */
    public function update(): ?static
    {
        foreach ($this->getElements() as $element) {
            $element->update();
        }
        return $this;
    }

    /**
     * @return Entity|null Batch persists all Entities from Set
     */
    public function batchUpdate(): void
    {
        $service = static::getService();
        if (!$service) {
            return;
        }
        $classWithNamespace = static::getClassWithNamespace();
        $updateMethod = 'batchUpdate';
        if (method_exists($service, $updateMethod)) {
            $updatedEntity = $service->$updateMethod($this);
            return;
        }
        $updateMethod = 'batchUpdate' . $classWithNamespace->name;
        if (method_exists($service, $updateMethod)) {
            $updatedEntity = $service->$updateMethod($this);
            return;
        }
        return;
    }

    /**
     * @return Entity|null Deletes all Entities from set
     */
    public function delete(): void
    {
        foreach ($this->getElements() as $element) {
            $element->delete();
        }
    }

    /** Returns the Service for this EntitySet */
    public static function getService(): ?Service
    {
        $currentClassName = AppService::instance()->getContainerServiceClassNameForClass(static::class);
        if (isset(StaticRegistry::$entityServices[$currentClassName])) {
            return StaticRegistry::$entityServices[$currentClassName];
        }
        $entityServiceName = null;
        /** @var EntitySet $currentClassName */
        if ($currentClassName::SERVICE_NAME) {
            $entityServiceName = $currentClassName::SERVICE_NAME;
        } else {
            $classWithNamespace = $currentClassName::getClassWithNamespace();
            $currentDomain = implode("\\", array_slice(explode("\\", $classWithNamespace->namespace), 0, 3));
            $entityServiceName = $currentDomain . "\\Services\\" . $classWithNamespace->name . 'Service';
        }
        if (class_exists($entityServiceName)) {
            StaticRegistry::$entityServices[$currentClassName] = AppService::instance()->getService($entityServiceName);
        } else {
            StaticRegistry::$entityServices[$currentClassName] = null;
        }
        return StaticRegistry::$entityServices[$currentClassName];
    }

    /**
     * Returns true, if the current Entities contained in the current EntitySet depends on the given Entity or Set
     * An Entity depends on another, when contains an id of the other, e.g. Project depends on Account when it has an accountId and LazyLoad on the Account
     * @param Entity|EntitySet $entityOrSet
     * @return void
     */
    public static function dependsOn(Entity|EntitySet &$entityOrSet): bool
    {
        /** @var Entity $entityClass */
        $entityClass = self::getEntityClass();
        return $entityClass::dependsOn($entityOrSet);
    }

    /**
     * Returns the property name for the property containing the id of the parent Entity given. If not found, returns false.
     * E.g. returns when called on AdSet with a Campaign (parent Entity) given, it will return campaignId
     * @param Entity|EntitySet $entityOrSet
     * @param string $propertyName
     * @return string|bool
     */
    public static function getPropertyContainingIdForParentEntity(Entity|EntitySet &$entityOrSet): string|bool
    {
        if (!static::dependsOn($entityOrSet)) {
            return false;
        }
        /** @var Entity $entityClass */
        $entityClass = static::getEntityClass();
        if (!$entityClass) {
            return false;
        }
        return $entityClass::getPropertyContainingIdForParentEntity($entityOrSet);
    }
}
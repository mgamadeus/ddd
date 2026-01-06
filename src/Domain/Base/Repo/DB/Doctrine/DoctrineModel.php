<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\Proxy;
use ReflectionAttribute;
use ReflectionException;
use ReflectionProperty;

abstract class DoctrineModel
{
    public const MODEL_ALIAS = '';

    public const TABLE_NAME = '';

    public const ENTITY_CLASS = '';

    protected static $tableNameCache = [];

    public array $jsonMergableColumns = [];

    public static array $virtualColumns = [];

    /**
     * Returns table name for Model
     * @return string|null
     * @throws ReflectionException
     */
    public static function getTableName(): ?string
    {
        if (isset(self::$tableNameCache[static::class])) {
            return self::$tableNameCache[static::class];
        }
        $tableName = null;
        $reflectionClass = ReflectionClass::instance(static::class);
        // if the model inherits another model (e.g. in case of single table inheritance)
        // then we use the definitions of the parent class
        if (is_subclass_of($reflectionClass->getParentClass()->getName(), DoctrineModel::class)) {
            $reflectionClass = ReflectionClass::instance($reflectionClass->getParentClass()->getName());
        }
        if ($tableAttributeInstance = ($reflectionClass->getAttributeInstance(ORM\Table::class, ReflectionAttribute::IS_INSTANCEOF))) {
            /** @var ORM\Table $tableAttributeInstance */
            $tableName = $tableAttributeInstance->name;
        }
        self::$tableNameCache[static::class] = $tableName;
        return $tableName;
    }

    /**
     * Verifies if an expression like site_competiors.source is valid for model, if not, returns false, else true
     * This is usefull e.g. in order to assure that filters that are not DB related are not applied to DB queries
     * @param string $expression
     * @param string $baseModelClass
     * @return bool
     * @throws ReflectionException
     */
    public static function isValidDatabaseExpression(string $expression): bool
    {
        $baseModelReflectionClass = ReflectionClass::instance(static::class);
        if ($baseModelReflectionClass) {
            $expression = explode('.', $expression);
            if (isset($expression[0]) && isset($expression[1])) {
                if (
                    ($expression[0] == static::MODEL_ALIAS) && $baseModelReflectionClass->hasProperty(
                        $expression[1]
                    )
                ) {
                    $reflectionProperty = $baseModelReflectionClass->getProperty($expression[1]);
                    if ($reflectionProperty->hasAttribute(ORM\Column::class)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Returns the class name of DoctrineModel of the target property name
     * @param string $propertyName
     * @return string|null
     * @throws ReflectionException
     */
    public static function getTargetModelClassForPropertyName(string $propertyName): ?string
    {
        $reflectionClass = ReflectionClass::instance(static::class);
        $reflectionProperty = $reflectionClass->getProperty($propertyName);
        if (!$reflectionProperty) {
            return null;
        }
        return static::getTargetModelClassForProperty($reflectionProperty);
    }

    /**
     * Returns the class name of DoctrineModel of the target property
     * @param string $propertyName
     * @return string|null
     * @throws ReflectionException
     */
    public static function getTargetModelClassForProperty(ReflectionProperty &$reflectionProperty): ?string
    {
        // in case of ManyToOne properties, the Type is already the desired one
        if (is_a($reflectionProperty->getType()->getName(), DoctrineModel::class, true)) {
            return $reflectionProperty->getType()->getName();
        } elseif ($oneToManyAttribue = ($reflectionProperty->getAttributes(ORM\OneToMany::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)) {
            /** @var ORM\OneToMany $oneToManyAttribueInstance */
            $oneToManyAttribueInstance = $oneToManyAttribue->newInstance();
            return $oneToManyAttribueInstance->targetEntity;
        }
        return null;
    }

    /**
     * This Function takes all the properties in a repository and maps them to the Model Object
     * @param $entity
     * @return $this
     */
    public function setPropertiesFromOtherModel($entity): DoctrineModel
    {
        $reflection = ReflectionClass::instance($entity::class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $property->setAccessible(true);
            $propertyName = $property->getName();
            $propertyValue = $property->isInitialized($entity) ? $property->getValue($entity) : null;

            if (array_key_exists($propertyName, $this->getClassVars()) && !is_null($propertyValue)) {
                $this->$propertyName = $propertyValue;
            }
        }

        return $this;
    }

    public function getClassVars(): array
    {
        return get_class_vars($this::class);
    }

    /**
     * Returns true if property is initialized (already loaded) without trigering lazyload
     * @param string $property
     * @return bool
     */
    public function isLoaded(string $property): bool
    {
        // *ToMany Association are PersistentCollection and will have the isInitialized property as true if it's loaded
        if ($this->{$property} instanceof PersistentCollection) {
            return $this->{$property}->isInitialized();
        }
        // *ToOne Associations are (sometimes) Proxy and will be marked as __isInitialized() when they are loaded
        if ($this->{$property} instanceof Proxy) {
            return $this->{$property}->__isInitialized();
        }
        // NOTE: Doctrine Associations will not be ArrayCollections. And they don't implement isInitalized so we really
        // can tell with certainty whether it's initialized or loaded. But if you join entities manually and want to check
        // you will need to set an internal mapper that records when you've loaded them. You could return true if count > 0
        if ($this->{$property} instanceof ArrayCollection) {
            //  NOTE: __isLoaded[$property] is an internal property we record on the Setter of special properties we know are ArrayCollections
            return (!empty($this->__isLoaded[$property]) || $this->{$property}->count() > 0);
        }

        // NOTE: there are never any Collections that aren't ArrayCollection or PersistentCollection (and it does no good to check because they won't have isInitialized() on them anyway

        // If it's an object after the checks above, we know it's not NULL and thus it is "probably" loaded because we know it's not a Proxy, PersistentCollection or ArrayCollection
        if (is_object($this->{$property})) {
            return true;
        }
        // If it's not null, return true, otherwise false. A null regular property could return false, but it's not an Entity or Collection so indeed it is not loaded.
        return !is_null($this->{$property});
    }
}
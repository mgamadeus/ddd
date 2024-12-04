<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Arr;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Traits\ReflectorTrait;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\ExposePropertyInsteadOfClass;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use Error;
use Exception;
use ReflectionException;
use stdClass;

trait SerializerTrait
{
    use ReflectorTrait;

    /** @var array Tracks usnet properties */
    protected $unsetProperties = [];

    public function unset(string $propertyName)
    {
        unset($this->$propertyName);
        $this->unsetProperties[$propertyName] = true;
    }

    /**
     * clears all marks for given index
     * @param $index
     * @return void
     */
    public static function clearAllMarksForIndex(string $index)
    {
        if (isset(SerializerRegistry::$marks[$index])) {
            unset(SerializerRegistry::$marks[$index]);
        }
    }

    /**
     * @return void Implement this method if object rewquires modifications, such as media item body needs to be nulled due to non utf8 chars
     */
    public function onToObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true
    ): void {
    }

    /**
     * recusively converts current entity to a stdClass object: top level entry function,
     * calls processPropertyForSerialization on properties
     * @param $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @return array
     * @throws ReflectionException
     */
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true
    ): mixed {
        $this->onToObject(
            $cached,
            $returnUniqueKeyInsteadOfContent,
            $path,
            $ignoreNullValues,
            $ignoreNullValues,
            $forPersistence
        );
        // in order to avoid caching objects, manipulating them in the meantime and then having an outdated cache, on the first call
        // we clear the SerializerRegistry. Currently this is deactivated, if problems occur, it can be activated again
        /*if (empty($path)){
            SerializerRegistry::clearToObjectCache();
        }*/
        $objectId = spl_object_id($this);
        $entityId = is_a($this, Entity::class, true) && isset($this->id) ? static::class . '_' . $this->id : null;
        if ($cached) {
            if ($cachedResult = SerializerRegistry::getToObjectCacheForObjectId($objectId)) {
                return $cachedResult;
            }
            /* This creates problems
            if ($entityId) {
                if ($cachedResult = SerializerRegistry::getToObjectCacheForObjectId($entityId)) {
                    return $cachedResult;
                }
            }*/
        }

        // on each iteraton we add the current spl_obejct_hash to the path. when trying to add the same id again,
        $path[$objectId] = true;
        if ($entityId) {
            $path[$entityId] = true;
        }
        // this means we are in an recursion loop and have to skip

        $resultArray = [];

        $propertyNameToExposeInsteadOfClass = null;
        $reflectionClass = new ReflectionClass($this);
        foreach ($reflectionClass->getAttributes(ExposePropertyInsteadOfClass::class) as $classAttribute) {
            $attributeInstance = $classAttribute->newInstance();
            $propertyNameToExposeInsteadOfClass = $attributeInstance->propertyNameToExpose;
        }

        foreach ($this->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if ($propertyNameToExposeInsteadOfClass && $propertyName != $propertyNameToExposeInsteadOfClass) {
                continue;
            }
            $visiblePropertyName = $propertyName;
            //attribute OverwritePropertyName changes the visible name of an atribute on serialization
            foreach ($property->getAttributes(OverwritePropertyName::class) as $attribute) {
                $attributeInstance = $attribute->newInstance();
                $visiblePropertyName = $attributeInstance->name;
            }
            if ((!$ignoreNullValues && $property->isInitialized($this) && $property->isPublic() && !$property->isStatic(
                    )) || ($ignoreNullValues && isset($this->$propertyName))) { // we process only properties that are initialized
                if (!$ignoreHideAttributes && $property->getAttributes(HideProperty::class)) {
                    continue;
                }
                if (!$forPersistence && $property->getAttributes(DontPersistProperty::class)) {
                    continue;
                }
                $propertyValue = $this->$propertyName;
                $serializedValue = $this->serializeProperty(
                    $propertyValue,
                    $cached,
                    $returnUniqueKeyInsteadOfContent,
                    $path,
                    $ignoreHideAttributes,
                    $ignoreNullValues,
                    $forPersistence
                );
                if ($serializedValue !== '*RECURSION*') // serialization created recursion loop, we skip the property
                {
                    $resultArray[$visiblePropertyName] = $serializedValue;
                }
            }
            if ($propertyNameToExposeInsteadOfClass) {
                // in case we are here, we just ran through the property that is to be exposed, since otherwise we would have continued;
                $resultArray = $resultArray[$visiblePropertyName];
                break;
            }
        }
        if ($cached) {
            SerializerRegistry::setToObjectCacheForObjectId($objectId, $resultArray);
            /*
            if ($entityId) {
                SerializerRegistry::setToObjectCacheForObjectId($entityId, $resultArray);
            }*/
        }
        if (empty($resultArray)) {
            return new stdClass(); // Leeres Objekt statt leeres Array zurückgeben
        }

        return $resultArray;
    }

    /**
     * recursively serializes property
     * @param mixed $propertyValue
     * @param $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @return mixed
     */
    private function serializeProperty(
        mixed &$propertyValue,
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true
    ): mixed {
        $propertyValueIsArray = is_array($propertyValue);
        $propertyValueIsObject = is_object($propertyValue);

        if (!$propertyValueIsArray && !$propertyValueIsObject) {
            //simple type e.g. string
            return $propertyValue;
        } elseif ($propertyValueIsObject && method_exists($propertyValue, 'jsonSerialize')) {
            if (method_exists($propertyValue, 'toObject')) {
                // we are in an object that uses the Serializer Trait
                if ($returnUniqueKeyInsteadOfContent && method_exists($propertyValue, 'uniqueKey')) {
                    return $propertyValue->uniqueKey();
                } elseif (isset($path[spl_object_id($propertyValue)])) {
                    // we had the object by its object hash already in the path, so we are in a recursion
                    return '*RECURSION*';
                } elseif (is_a(
                        $propertyValue,
                        Entity::class,
                        true
                    ) && isset($propertyValue->id) && isset($path[$propertyValue::class . '_' . $propertyValue->id])) {
                    // we had the object (found by entity id) already in the path, so we are in a recursion
                    return '*RECURSION*';
                } else {
                    return $propertyValue->toObject(
                        $cached,
                        $returnUniqueKeyInsteadOfContent,
                        $path,
                        $ignoreHideAttributes,
                        $ignoreNullValues
                    );
                }
            } else {  // we are in a general object that supports JSON Serialization
                return $propertyValue->jsonSerialize();
            }
        } elseif (
            $propertyValueIsArray || ($propertyValueIsObject && !method_exists($propertyValue, 'jsonSerialize'))
        ) {
            // we have an unkown object which has a toString function, return string number
            if ($propertyValueIsObject && method_exists($propertyValue, '__toString')) {
                return (string)$propertyValue;
            }
            $returnArray = [];
            $elementCount = 0;
            foreach ($propertyValue as $tKey => $tValue) {
                $elementCount++;
                $serializedArrayValue = $this->serializeProperty(
                    $tValue,
                    $cached,
                    $returnUniqueKeyInsteadOfContent,
                    $path,
                    $ignoreHideAttributes,
                    $ignoreNullValues,
                    $forPersistence
                );
                // we skip empty values and values that created a recursion loop
                if ($serializedArrayValue !== null && $serializedArrayValue !== '*RECURSION*') {
                    $returnArray[$tKey] = $serializedArrayValue;
                }
            }
            // avoid delivering empty array for empty objects in serialization, this leads to issues e.g. with OA schema on empty properties
            if (!$elementCount && $propertyValueIsObject) {
                return (object)$returnArray;
            }
            return $returnArray;
        }
        return null;
    }

    public function jsonSerialize(bool $ignoreHideAttributes = false)
    {
        // unset toObject Cache
        SerializerRegistry::$toOjectCache = [];
        return $this->toObject(ignoreHideAttributes: $ignoreHideAttributes);
    }

    /**
     * return if this OrmEntity is marked with $index
     * @param $index
     * @return bool
     */
    public function isMarked(string $index): bool
    {
        return isset(SerializerRegistry::$marks[$index]) && isset(
                SerializerRegistry::$marks[$index][spl_object_id(
                    $this
                )]
            );
    }

    /**
     * marks Object with index
     * @param $index
     * @return bool|void
     */
    public function mark(string $index): void
    {
        if (!isset(SerializerRegistry::$marks[$index])) {
            SerializerRegistry::$marks[$index] = [];
        }
        SerializerRegistry::$marks[$index][spl_object_id($this)] = $this;
    }

    /**
     * returns if an index for marks already exists
     * it can be used to identify if a recursive function is called on the highest level,
     * e.g. if the function sets and unsets the index at the beginning and end of execution
     * e.g.
     * function recursive(){
     *   if ($this->markIndexExists('myMark')){
     *      $recursion = true
     *   }
     *   $this->mark('myMark');
     *   ...
     *
     *   if (!$recursion){
     *      clearAllMarksForIndex('myMark');
     *   }
     * }
     * @param string $index
     * @return bool
     */
    public function markIndexExists(string $index): bool
    {
        return isset(SerializerRegistry::$marks[$index]);
    }

    /**
     * removes marks for index
     * @param $index
     * @param false $recursive
     */
    public function unmark($index): void
    {
        if (!isset(SerializerRegistry::$marks[$index])) {
            return;
        }
        if (!isset(SerializerRegistry::$marks[$index][spl_object_id($this)])) {
            return;
        }
        unset(SerializerRegistry::$marks[$index][spl_object_id($this)]);
    }

    public function __toString()
    {
        return $this->toJSON();
    }

    public function toJSON(bool $ignoreHideAttributes = false)
    {
        return json_encode(
            $this->jsonSerialize($ignoreHideAttributes),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * we need to avoid to serialize values, that are not ment to be serialized, e.g. partent, children
     * @return array
     */
    public function __serialize(): array
    {
        $return = ['unset' => [], 'properties' => []];
        foreach ($this->getProperties(null, true) as $property) {
            $propertyName = $property->getName();
            $isStatic = $property->isStatic();
            // if property is not set, serializer will wake up the classs with the property default, this creates problems with autoloading
            // as __get is not called anymore
            if (!$isStatic && !isset($this->$propertyName) && isset($this->unsetProperties[$propertyName])) {
                $return['unset'][$propertyName] = true;
                continue;
            }
            if ($property->getAttributes(HidePropertyOnSystemSerialization::class)) {
                //Lazyload Attributes need to be unset if we want to hide them so lazyloading will work properly with __get
                if ($property->getAttributes(LazyLoad::class)) {
                    $return['unset'][$propertyName] = true;
                }
                continue;
            }
            // without the following line we have issues to access private properties even in $this context, I think a PHP bug
            $property->setAccessible(true);
            if ($property->isInitialized($this)) {
                $return['properties'][$propertyName] = $property->getValue($this);
            }
        }
        return $return;
    }

    public function __unserialize($data)
    {
        $unserialized = $data;//unserialize($data);
        foreach ($unserialized['properties'] as $key => $value) {
            $reflectionProperty = new \ReflectionProperty($this, $key);
            // without the following line we have issues to access private properties even in $this context, I think a PHP bug
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($this, $value);
            $reflectionProperty->setAccessible(false);
        }
        foreach ($unserialized['unset'] as $key => $true) {
            $this->unset($key);
        }
    }

    /**
     * completely fills this object with the property content of another object of the same type which was serialized before
     * @param mixed $otherObject
     * @return void
     * @throws ReflectionException
     */
    public function setPropertiesFromSerializedObject(mixed &$otherObject)
    {
        foreach ($this->getProperties() as $property) {
            $valueFromOther = $otherObject->getDataForProperty($property->getName());
            $property->setAccessible(true);
            $property->setValue($this, $valueFromOther);
        }
    }

    /**
     * gets data from property indiferent if it is private or static etc. by use of reflection
     * @param string $propertyName
     * @return mixed|null
     */
    public function getDataForProperty(string $propertyName)
    {
        $property = $this->getProperty($propertyName);
        $property->setAccessible(true);
        if ($property->isInitialized($this)) {
            return $property->getValue($this);
        }
        return null;
    }

    /**
     * Recursively fills current isntance with data from a default object.
     * It recursively constructs an object structure below the current instance based on a default object
     * It uses reflection in order to determine the allowed type/types of each property tries to apply the data from the default object.
     * Is also able to fill array with instances of the allowed array types.
     * If $throwErrors is true, it throws BadRequest if the data is not matching class definitions and InternalError if problems with
     * the class definitions are found.
     * In case of multiple allowed types (union types), the default object needs to provide by convention an objectType with a fully qualified
     * class name of the object to be instanced, that is one of the allowed types.
     *
     * It uses a static cache for Entities, by Class name and id, and clears this cache after execution of root call, for this purpose
     * also a rootCall parameter is required.
     * @param object $object
     * @param $throwErrors
     * @param bool $rootCall
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
        $reflectionClass = $this->getReflectionClass();
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isReadOnly()) {
                continue;
            }
            $propertyName = $property->getName();
            $setProperty = false;
            // we write the property if it is set, means it has a value or it is null
            // in case of null, we take care to check if the target value supports null
            if (ReflectionClass::isPropertyInitialized($object, $propertyName)) {
                if ($object->$propertyName === null) {
                    $setProperty = $property->allowsNull();
                } else {
                    $setProperty = true;
                }
            }
            if ($setProperty) {
                $this->setPropertyFromObject(
                    $property,
                    $object->$propertyName,
                    $throwErrors,
                    $reflectionClass,
                    $sanitizeInput
                );
            }
        }
        if (is_a($this, Entity::class, true) && isset($this->id) && $this->id) {
            SerializerRegistry::setInstanceForSetPropertiesFromObjectCache($this);
        }
        if ($rootCall) {
            SerializerRegistry::clearSetPropertiesFromObjectCache();
        }
    }

    /**
     * @param \ReflectionProperty|ReflectionProperty $property
     * @param mixed $value
     * @param $throwErrors
     * @param ReflectionClass|null $reflectionClass
     * @param bool $rootCall
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertyFromObject(
        \ReflectionProperty|ReflectionProperty &$property,
        mixed &$value,
        $throwErrors = true,
        ReflectionClass $reflectionClass = null,
        bool $sanitizeInput = false
    ): void {
        $propertyName = $property->getName();
        if ($reflectionClass) {
            $reflectionClass = $this->getReflectionClass();
        }
        $allowedTypes = $reflectionClass->getAllowedTypesForProperty($property);

        // type is array
        if ($allowedTypes->isArrayType) {
            // we have by design an array but $number is not an array
            if (!is_array($value)) {
                if (is_object($value)) {
                    $value = Arr::fromObject($value);
                } else {
                    if ($throwErrors) {
                        throw new BadRequestException(
                            'Property ' . static::class . '->' . $propertyName . ' has to be array'
                        );
                    }
                    return;
                }
            }
            // the types allowed for current array items

            $this->$propertyName = [];
            // we iterate through the array elements
            foreach ($value as $index => $arrayItem) {
                $valueType = gettype($arrayItem);
                //get_type returns e.g. integer, we map this to int
                $valueTypeAllocated = ReflectionClass::GET_TYPE_ALLOCATIONS[$valueType] ?? 'object';
                $valueIsScalar = $valueTypeAllocated != 'object' && $valueTypeAllocated != 'array';

                // if array element is of type array, we let it be and just pass the data
                if (isset($allowedTypes->allowedTypes[ReflectionClass::ARRAY])) {
                    if (is_int($index)) {
                        $this->$propertyName[] = $arrayItem;
                    } else {
                        $this->{$propertyName}[$index] = $arrayItem;
                    }
                    continue;
                }

                // exact types are found, should happen only on scalar types
                if (isset($allowedTypes->allowedTypes[$valueTypeAllocated])) {
                    if ($sanitizeInput) {
                        $arrayItem = Datafilter::sanitizeInput($arrayItem);
                    }
                    $this->$propertyName[] = $arrayItem;
                    continue;
                }
                if (!$allowedTypes->allowsObject && $valueIsScalar) {
                    if (
                        (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER]) || isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) && is_numeric(
                            $arrayItem
                        )
                    ) {
                        if (isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) {
                            $this->$propertyName[] = (float)$arrayItem;
                            continue;
                        }
                        if (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER])) {
                            $this->$propertyName[] = (int)$arrayItem;
                            continue;
                        }
                    }
                    if (isset($allowedTypes->allowedTypes[ReflectionClass::STRING]) && (is_string(
                                $arrayItem
                            ) || is_numeric($arrayItem))) {
                        if ($sanitizeInput) {
                            $arrayItem = Datafilter::sanitizeInput($arrayItem);
                        }
                        $this->$propertyName[] = (string)$arrayItem;
                        continue;
                    }
                    if (
                        isset($allowedTypes->allowedTypes[ReflectionClass::BOOL]) && (is_bool(
                                $arrayItem
                            ) || $arrayItem === 'true' || $arrayItem == 1.0 || $arrayItem == 1)
                    ) {
                        $this->$propertyName[] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        continue;
                    }
                }
                if (!$allowedTypes->allowsObject && !$valueIsScalar) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided on index ' . $index
                    );
                }
                if (!$allowedTypes->allowsScalar && $valueIsScalar) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided on index ' . $index
                    );
                }

                $typeToInstance = null;
                if ($allowedTypes->allowedTypesCount > 1) {
                    // by convention we need an objectType in case of multiple types possible
                    if (isset($value->objectType) && isset($allowedTypes->allowedTypes[$value->objectType])) {
                        $typeToInstance = $value->objectType;
                    } else {
                        $validType = false;
                        foreach ($allowedTypes->allowedTypes as $allowedType => $true) {
                            if (isset($value->objectType) && is_a($value->objectType, $allowedType, true)) {
                                $typeToInstance = $value->objectType;
                                $validType = true;
                                break;
                            }
                        }
                        if (!$validType) {
                            if ($throwErrors) {
                                if (isset($value->objectType)) {
                                    throw new BadRequestException(
                                        'Property ' . static::class . '->elements needs to be of type ' . implode(
                                            '|',
                                            array_keys($allowedTypes->allowedTypes)
                                        ) . ', the provided objectType ' . $value->objectType . ' is not supported on index ' . $index
                                    );
                                } else {
                                    throw new BadRequestException(
                                        'Property ' . static::class . '->elements needs to be of type ' . implode(
                                            '|',
                                            array_keys($allowedTypes->allowedTypes)
                                        ) . ', an objectType is required in order to identify the type, but not provided on index ' . $index
                                    );
                                }
                            }
                            return;
                        }
                    }
                } else {
                    // we allow a single type
                    $typeToInstance = array_key_first($allowedTypes->allowedTypes);
                    // we allow subclasses as well if the objectType is of a subclass
                    if (isset($value->objectType)) {
                        if (is_a($value->objectType, $typeToInstance, true)) {
                            $typeToInstance = $value->objectType;
                        } else {
                            // objectType does not correspond with type to instance, skip this object
                            $typeToInstance = null;
                        }
                    }
                    // scalar type was not correctly allocated
                    if ($allowedTypes->allowsScalar) {
                        throw new BadRequestException(
                            'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . $typeToInstance . ' but value on index ' . $index . ' was identified as ' . $valueType
                        );
                    }
                }
                // we first try to check if the type to instance is an entity and if we already have set it's properties
                // in this case we can use the SerializerRegistry
                $entityFromCache = false;
                if (is_a(
                        $typeToInstance,
                        Entity::class,
                        true
                    ) && isset($arrayItem->id) && $arrayItem->id && $cachedEntityInstance = SerializerRegistry::getInstanceForSetPropertiesFromObjectCache(
                        $typeToInstance,
                        $arrayItem->id
                    )) {
                    $item = $cachedEntityInstance;
                    $entityFromCache = true;
                } elseif (!$typeToInstance) {
                    $item = null;
                } else {
                    $item = new $typeToInstance();
                }

                if ($item) {
                    if (!$entityFromCache && method_exists($item, 'setPropertiesFromObject')) {
                        $item->setPropertiesFromObject($arrayItem, $throwErrors, false, $sanitizeInput);
                    }
                    $this->$propertyName[] = $item;
                    $this->addChildren($item);
                    if (method_exists($item, 'setParent')) {
                        $item->setParent($this);
                    }
                }
            }
        } else {
            // handle null
            if ($value === null && $allowedTypes->allowsNull) {
                $this->$propertyName = null;
                return;
            } elseif ($value === null && !$allowedTypes->allowsNull) {
                if ($throwErrors) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' does not allow null'
                    );
                }
                return;
            }
            $valueType = gettype($value);
            //get_type returns e.g. integer, we map this to int
            $valueTypeAllocated = ReflectionClass::GET_TYPE_ALLOCATIONS[$valueType] ?? 'object';
            $valueIsScalar = $valueTypeAllocated != 'object' && $valueTypeAllocated != 'array';

            // exact types are found, should happen only on scalar types
            if (isset($allowedTypes->allowedTypes[$valueTypeAllocated])) {
                if ($sanitizeInput) {
                    $value = Datafilter::sanitizeInput($value);
                }
                $this->$propertyName = $value;
                return;
            }
            if (!$allowedTypes->allowsObject && $valueIsScalar) {
                if (
                    (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER]) || isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) && is_numeric(
                        $value
                    )
                ) {
                    if (isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) {
                        $this->$propertyName = (float)$value;
                        return;
                    }
                    if (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER])) {
                        $this->$propertyName = (int)$value;
                        return;
                    }
                }
                if (isset($allowedTypes->allowedTypes[ReflectionClass::STRING]) && (is_string($value) || is_numeric(
                            $value
                        ))) {
                    if ($sanitizeInput) {
                        $value = Datafilter::sanitizeInput($value);
                    }
                    $this->$propertyName = (string)$value;
                    return;
                }
                if (
                    isset($allowedTypes->allowedTypes[ReflectionClass::BOOL]) && (is_bool(
                            $value
                        ) || $value === 'true' || $value === '' || $value == 1.0 || $value == 1 || $value === 'false' || $value == 0.0 || $value == 0)
                ) {
                    $this->$propertyName = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    return;
                }
            }
            if (!$allowedTypes->allowsObject && !$valueIsScalar) {
                if ($throwErrors) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided'
                    );
                }
                return;
            }
            if (!$allowedTypes->allowsScalar && $valueIsScalar) {
                // special handling for Classes supporting fromString function, e.g. DateTime
                if (count($allowedTypes->allowedTypes) == 1) {
                    $typeToInstance = array_key_first($allowedTypes->allowedTypes);
                    if (method_exists($typeToInstance, 'fromString')) {
                        if (!$throwErrors) {
                            try {
                                $loadedInstance = $typeToInstance::fromString($value);
                                if ($loadedInstance) {
                                    $this->$propertyName = $loadedInstance;
                                }
                                return;
                            } catch (Exception) {
                            }
                        } else {
                            $loadedInstance = $typeToInstance::fromString($value);
                            if ($loadedInstance) {
                                $this->$propertyName = $loadedInstance;
                            }
                            return;
                        }
                    }
                }
                if ($throwErrors) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided'
                    );
                }
                return;
            }

            $typeToInstance = null;
            if ($allowedTypes->allowedTypesCount > 1) {
                // by convention we need an objectType property in case of multiple types possible
                if (isset($value->objectType) && isset($allowedTypes->allowedTypes[$value->objectType])) {
                    $typeToInstance = $value->objectType;
                } else {
                    $validType = false;
                    foreach ($allowedTypes->allowedTypes as $allowedType => $true) {
                        $invalidClass = false;
                        try {
                            if (isset($value->objectType) && !class_exists($value->objectType)) {
                                $invalidClass = true;
                            }
                        } catch (Exception) {
                            $invalidClass = true;
                        }

                        if (!$invalidClass && isset($value->objectType) && is_a(
                                $value->objectType,
                                $allowedType,
                                true
                            )) {
                            $typeToInstance = $value->objectType;
                            $validType = true;
                            break;
                        }
                    }
                    if (!$validType) {
                        if ($throwErrors) {
                            if (isset($value->objectType)) {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->elements needs to be of type ' . implode(
                                        '|',
                                        array_keys($allowedTypes->allowedTypes)
                                    ) . ', the provided objectType "' . $value->objectType . '" is not supported'
                                );
                            } else {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                                        '|',
                                        array_keys($allowedTypes->allowedTypes)
                                    ) . ', an objectType is required in order to identify the type'
                                );
                            }
                        }
                        return;
                    }
                }
            } else {
                // we allow a single type
                $typeToInstance = array_key_first($allowedTypes->allowedTypes);
                // we allow subclasses as well if the objectType is of a subclass
                if (isset($value->objectType)) {
                    if (is_a($value->objectType, $typeToInstance, true)) {
                        $typeToInstance = $value->objectType;
                    } else {
                        // objectType does not correspond with type to instance, skip this object
                        $typeToInstance = null;
                    }
                }
                // scalar type was not correctly allocated
                if ($allowedTypes->allowsScalar) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . $typeToInstance . ' but value was identified as ' . $valueType
                    );
                }
            }

            try {
                $entityFromCache = false;
                if (is_a(
                        $typeToInstance,
                        Entity::class,
                        true
                    ) && isset($value->id) && $value->id && $cachedEntityInstance = SerializerRegistry::getInstanceForSetPropertiesFromObjectCache(
                        $typeToInstance,
                        $value->id
                    )) {
                    $this->$propertyName = $cachedEntityInstance;
                    $entityFromCache = true;
                } elseif ($typeToInstance) {
                    $this->$propertyName = new $typeToInstance();
                }
            } catch (Error $e) {
                throw new InternalErrorException(
                    'Property ' . static::class . '->' . $propertyName . ' is defined to be of type ' . implode(
                        '|',
                        array_keys($allowedTypes->allowedTypes)
                    ) . ', but class does not exist: ' . $typeToInstance
                );
            }
            if (isset($this->$propertyName) && $this->$propertyName) {
                if (!$entityFromCache && method_exists($this->$propertyName, 'setPropertiesFromObject')) {
                    // empty objects come as arrays and have to be converted
                    if (is_array($value)) {
                        $value = (object)$value;
                    }
                    if (!$entityFromCache) {
                        $this->$propertyName->setPropertiesFromObject($value, $throwErrors, false, $sanitizeInput);
                    }
                }
                if (method_exists($this, 'addChildren')) {
                    $this->addChildren($this->$propertyName);
                    if (method_exists($this->$propertyName, 'setParent')) {
                        $this->$propertyName->setParent($this);
                    }
                }
            }
            return;
        }
    }
}
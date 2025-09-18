<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use ArrayAccess;
use Countable;
use DDD\Domain\Base\Entities\Interfaces\IsEmptyInterface;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionArrayType;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionNamedType;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Reflection\ReflectionUnionType;
use DDD\Infrastructure\Traits\Serializer\SerializerRegistry;
use Exception;
use Iterator;
use ReflectionException;

class ObjectSet extends ValueObject implements ArrayAccess, Iterator, Countable, IsEmptyInterface
{
    /** @var BaseObject[] */
    public array $elements = [];
    /** @var BaseObject[] */
    protected array $elementsByUniqueKey = [];
    protected int $iteratorPosition = 0;
    protected int $elementCount = 0;
    /**
     * @var bool Determines if when adding elements they are also added as children or not. This needs to set to false for
     * children ObjectSet in ParentChildrenTrait as we otherwise run into a recursion
     */
    protected $addAsChild = true;

    /**
     * removes all values
     * @return void
     */
    public function clear(): void
    {
        $this->elements = [];
        $this->elementsByUniqueKey = [];
        $this->elementCount = 0;
        $this->iteratorPosition = 0;
    }

    /**
     * returns first element
     * @return BaseObject|null
     */
    public function first(): ?BaseObject
    {
        if (!$this->count()) {
            return null;
        }
        return $this->elements[0];
    }

    /**
     * Reeturns a set with a slice of the contained elements
     * @param int $start
     * @param int $count
     * @return $this
     */
    public function getElementsSlice(int $start, int $count): static
    {
        $objectSet = new static();
        $elementsSlice = $slice = array_slice($this->elements, $start, $count);
        $objectSet->add(...$elementsSlice);
        return $objectSet;
    }

    /**
     * returns the number of elements
     * @return int
     */
    public function count(): int
    {
        return $this->elementCount;
    }

    /**
     * returns last element
     * @return BaseObject|null
     */
    public function last(): ?BaseObject
    {
        if (!$this->count()) {
            return null;
        }
        return $this->elements[$this->count() - 1];
    }

    /**
     * returns Object by uniqueKey
     * @return DefaultObject[]
     */
    public function getElements(): array
    {
        return $this->elements;
    }

    /**
     * returns the element that has the given uniqueKey if present
     * @param string $uniqueKey
     * @return DefaultObject|null
     */
    public function getByUniqueKey(string $uniqueKey): ?BaseObject
    {
        return $this->elementsByUniqueKey[$uniqueKey] ?? null;
    }

    public function updateByUniqueKey(BaseObject &$baseObject)
    {
        if ($this->getByUniqueKey($baseObject->uniqueKey())) {
            $this->elementsByUniqueKey[$baseObject->uniqueKey()] = $baseObject;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $element = $this->offsetGet($offset);
        if ($element) {
            $this->remove($element);
        }
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($this->offsetExists($offset)) {
            return $this->elements[$offset];
        }
        return null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset <= $this->count() - 1;
    }

    /**
     * removes all elements provided
     * @param BaseObject ...$elements
     * @return void
     */
    public function remove(BaseObject &...$elements): void
    {
        $keysToDelete = [];
        foreach ($elements as $element) {
            unset($this->elementsByUniqueKey[$element->uniqueKey()]);
            $keysToDelete[$element->uniqueKey()] = true;
            $this->elementCount--;
            // BaseObject does not have ParentChildrenTrait and therefore we restrict this to DefaultObject
            if ($this->addAsChild && $element instanceof DefaultObject) {
                $this->children->remove($element);
            }
        }

        $elementsToKeep = [];
        foreach ($this->elements as $element) {
            if (!isset($keysToDelete[$element->uniqueKey()])) {
                $elementsToKeep[] = $element;
            }
        }
        $this->elements = $elementsToKeep;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($this->offsetExists($offset)) {
            $this->elements[$offset] = $value;
        }
    }

    public function next(): void
    {
        $this->iteratorPosition++;
    }

    public function rewind(): void
    {
        $this->iteratorPosition = 0;
    }

    public function key(): int
    {
        return $this->iteratorPosition;
    }

    public function current(): mixed
    {
        return $this->elements[$this->iteratorPosition] ?? null;
    }

    public function valid(): bool
    {
        return isset($this->elements[$this->iteratorPosition]);
    }

    public function sort(callable $comparator): void
    {
        usort($this->elements, $comparator);
    }

    public function setPropertiesFromObject(
        &$object,
        $throwErrors = true,
        bool $rootCall = true,
        bool $sanitizeInput = false
    ): void
    {
        if (isset($object->elements) && is_array($object->elements)) {
            $allowedTypes = [];
            $property = $this->getProperty('elements');
            $type = $property->getType();
            $arrayType = $type->getArrayType();
            if ($arrayType instanceof ReflectionUnionType) {
                foreach ($arrayType->getTypes() as $type) {
                    $typeName = $type->getName();
                    if (!$type->isBuiltin()) {
                        $allowsObject = true;
                    }
                    if ($typeName) {
                        if (isset(ReflectionClass::SCALAR_BASE_TYPES[$typeName])) {
                            $allowsScalar = true;
                        }
                        $allowedTypes[$typeName] = $type;
                    }
                }
            } else {
                $typeName = $arrayType->getName();
                $allowedTypes[$typeName] = $arrayType;
                if (!$arrayType->isBuiltin()) {
                    $allowsObject = true;
                }
                if (isset(ReflectionClass::SCALAR_BASE_TYPES[$typeName])) {
                    $allowsScalar = true;
                }
                $allowedTypes[$typeName] = $arrayType;
            }

            foreach ($object->elements as $index => $value) {
                $typeToInstance = null;
                if (count($allowedTypes) > 1) {
                    // by convention we need an objectType in case of multiple types possible
                    if (isset($value->objectType)) {
                        foreach ($allowedTypes as $allowedType => $bool) {
                            // we check if type to instance is valid type or child
                            if (is_a($value->objectType, $allowedType, true)) {
                                $typeToInstance = $value->objectType;
                            }
                        }
                    }
                    if (!$typeToInstance) {
                        if ($throwErrors) {
                            if (isset($value->objectType)) {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->elements needs to be of type ' . implode(
                                        '|',
                                        array_keys($allowedTypes)
                                    ) . ', the provided objectType "' . $value->objectType . '" is not supported on index ' . $index
                                );
                            } else {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->elements needs to be of type ' . implode(
                                        '|',
                                        array_keys($allowedTypes)
                                    ) . ', an objectType is required in order to identify the type, but not provided on index ' . $index
                                );
                            }
                        }
                        return;
                    }
                } elseif (isset($value->objectType) && is_a($value->objectType, array_keys($allowedTypes)[0], true)) {
                    // we have only one element
                    $typeToInstance = $value->objectType;
                } else {
                    $typeToInstance = array_key_first($allowedTypes);
                }

                if (!class_exists($typeToInstance, true)) {
                    throw new InternalErrorException(
                        'Property ' . static::class . '->elements is defined to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes)
                        ) . ', but class does not exist: ' . $typeToInstance
                    );
                }
                $entityFromCache = false;
                if (
                    DefaultObject::isEntity($typeToInstance) && isset($value->id) && $value->id && $cachedEntityInstance = SerializerRegistry::getInstanceForSetPropertiesFromObjectCache(
                        $typeToInstance,
                        $value->id
                    )
                ) {
                    $item = $cachedEntityInstance;
                    $entityFromCache = true;
                } else {
                    if (is_string($value) && method_exists($typeToInstance, 'fromString')) {
                        if (!$throwErrors) {
                            try {
                                $item = $typeToInstance::fromString($value);
                            } catch (Exception) {
                            }
                        } else {
                            $item = $typeToInstance::fromString($value);
                        }
                    } else {
                        $item = new $typeToInstance();
                    }
                }
                if (!$entityFromCache && !is_string($value) && method_exists($item, 'setPropertiesFromObject')) {
                    $item->setPropertiesFromObject($value, $throwErrors, false, $sanitizeInput);
                }
                $this->add($item);
            }
        }
        parent::setPropertiesFromObject($object, $throwErrors, $rootCall, $sanitizeInput);
    }

    /**
     * adds all elements
     * @param BaseObject ...$elements
     * @return void
     */
    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            if (!$element) {
                continue;
            }
            // Check if the element already exists in the ObjectSet
            if ($this->contains($element)) {
                continue;
            }
            if ($element instanceof DefaultObject) {
                $element->setParent($this);
            }

            // BaseObject does not have ParentChildrenTrait, and therefore we restrict this to DefaultObject
            if ($this->addAsChild && $element instanceof DefaultObject) {
                $this->addChildren($element);
            }

            // Add the element to the ObjectSet's internal list of elements
            $this->elements[] = $element;
            $this->elementCount++;
            $this->elementsByUniqueKey[$element->uniqueKey()] = $element;
        }
    }

    /**
     * Replaces existing elements with new elements. If new elements are not present, they are added.
     * If new elements have same uniqueKey as existing elements, the existing ones are replaced with new elements
     * @param BaseObject ...$elements
     * @return void
     */
    public function replace(BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            if (!$this->contains($element)) {
                $this->add($element);
            } else {
                foreach ($this->getElements() as $index => $iteratorElement) {
                    if ($iteratorElement->uniqueKey() == $element->uniqueKey()) {
                        $this->elements[$index] = $element;
                    }
                }
                $this->elementsByUniqueKey[$element->uniqueKey()] = $element;
            }
        }
    }

    /**
     * returns if all elements are contained
     * @param BaseObject ...$elements
     * @return bool
     */
    public function contains(?BaseObject &...$elements): bool
    {
        foreach ($elements as $element) {
            if (!$element) {
                continue;
            }
            if (!isset($this->elementsByUniqueKey[$element->uniqueKey()])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true, if at least one element is contained in current ObjectSet
     * @param BaseObject ...$elements
     * @return bool
     */
    public function containsOneOf(?BaseObject &...$elements): bool
    {
        foreach ($elements as $element) {
            if (!$element) {
                continue;
            }
            if ($this->contains($elemente)) {
                return true;
            }
        }
        return false;
    }

    private function resetIteratorPosition()
    {
        if ($this->iteratorPosition > $this->elementCount - 1) {
            $this->iteratorPosition = 0;
        }
    }

    /**
     * Set addAsChild, this determines if when adding elements they are also added as children or not. This needs to set to false for
     * children ObjectSet in ParentChildrenTrait as we otherwise run into a recursion
     * @param bool $addAschild
     * @return void
     */
    public function setAddAsChild(bool $addAschild)
    {
        $this->addAsChild = $addAschild;
    }

    /**
     * Sets all public properties from other set, that are not defined or set in current set
     * and adds all elements
     *
     * @param ObjectSet $otherSet
     *
     * @return void
     * @throws ReflectionException
     */
    public function mergeFromOtherSet(ObjectSet &$otherSet): void
    {
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if (!isset($this->$propertyName) && isset($otherSet->$propertyName)) {
                $this->$propertyName = $otherSet->$propertyName;
            }
        }
        foreach ($otherSet->getElements() as $element) {
            $this->add($element);
        }
    }

    /**
     * Returns true if sets contain the same elements independent of the order
     * @param ObjectSet $other
     * @return bool
     */
    public function containsSameElements(ObjectSet $other): bool
    {
        if (!$this->contains(...$other->getElements())) {
            return false;
        }
        if (!$other->contains(...$this->getElements())) {
            return false;
        }
        return true;
    }

    /**
     * Returns true if set contains same elements in the same order
     * @param ObjectSet $other
     * @return bool
     */
    public function containsSameElementsInSameOrder(ObjectSet $other): bool
    {
        $thisKeys = '';
        $otherKeys = '';
        foreach ($this->getElements() as $element) {
            $thisKeys .= $element->uniqueKey();
        }
        foreach ($other->getElements() as $element) {
            $otherKeys .= $element->uniqueKey();
        }
        return $thisKeys == $otherKeys;
    }

    /**
     * Returns Reflection type of elements array
     * @return ReflectionNamedType|ReflectionUnionType
     * @throws ReflectionException
     */
    public function getElementType(): ReflectionNamedType|ReflectionUnionType
    {
        $reflectionClass = ReflectionClass::instance(static::class);
        $reflectionProperty = $reflectionClass->getProperty('elements');
        /** @var ReflectionArrayType $arrayType */
        $arrayType = $reflectionProperty->getType();
        return $arrayType->getArrayType();
    }

    public function isEmpty(): bool
    {
        return !((bool)$this->count());
    }

    /**
     * @return BaseObject|null Returns random Element
     */
    public function rand(): ?BaseObject
    {
        if (!$this->count()) {
            return null;
        }
        return $this->elements[rand(0, $this->count() - 1)];
    }

    /**
     * Regenerate the unique keys of the object set. This is useful after persisting elements belonging to a ObjectSet
     * @return void
     */
    public function regenerateElementsByUniqueKey(): void
    {
        $this->elementsByUniqueKey = [];
        foreach ($this->getElements() as $element) {
            $this->elementsByUniqueKey[$element->uniqueKey()] = $element;
        }
    }
}

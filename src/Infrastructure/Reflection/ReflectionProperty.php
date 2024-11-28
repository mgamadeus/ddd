<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;


class ReflectionProperty extends \ReflectionProperty
{
    protected static $typeCache = [];
    private string $reflectionClassName;

    protected static array $attributesCache = [];

    public function __construct(object|string $class, string $property)
    {
        $this->reflectionClassName = $class;
        parent::__construct($class, $property);
    }


    public function getReflectionClass(): ReflectionClass
    {
        return ReflectionClass::instance($this->reflectionClassName);
    }

    public function getDocCommentInstance(): ReflectionDocComment
    {
        $reflectionDocComment = new ReflectionDocComment((string)$this->getDocComment());
        return $reflectionDocComment;
    }

    public function getType(): \ReflectionNamedType|ReflectionArrayType|\ReflectionUnionType|null
    {
        $cacheKey = $this->reflectionClassName . '_' . $this->getName();
        if (isset(self::$typeCache[$cacheKey])) {
            return self::$typeCache[$cacheKey];
        }
        //echo $cacheKey . "\n";
        $type = parent::getType();

        if ($type instanceof \ReflectionUnionType) {
            $return = $type;
        } elseif ((string)$type != 'array' && (string)$type != '?array') {
            $return = $type;
        } else {
            $return = new ReflectionArrayType($this, $type);
        }
        self::$typeCache[$cacheKey] = $return;
        return $return;
    }

    /**
     * @return bool Returns true if Type of one of the Types in UnionType allows null
     */
    public function allowsNull():bool {
        $type = $this->getType();
        if ($type instanceof \ReflectionNamedType){
            return $type->allowsNull();
        }
        elseif ($type instanceof \ReflectionUnionType){
            foreach ($type->getTypes() as $unionType){
                if ($unionType->allowsNull())
                    return true;
            }
        }
        return false;
    }

    /**
     * Cached getAttributes
     * @param string|null $attributeName
     * @param int $flags
     * @return ReflectionAttribute[]
     */
    public function getAttributes(?string $attributeName = null, int $flags = 0): array
    {
        $key = $this->reflectionClassName . '_' . $this->name;
        if (!isset(self::$attributesCache[$key])) {
            self::$attributesCache[$key] = [];
            $index = 0;
            foreach (parent::getAttributes() as $systemAttribute) {
                $attribute = new ReflectionAttribute($systemAttribute, $this->reflectionClassName, $this->name, $index);
                if (!isset(self::$attributesCache[$key][$attribute->getName()])) {
                    self::$attributesCache[$key][$attribute->getName()] = [];
                }
                self::$attributesCache[$key][$attribute->getName()][] = $attribute;
                $index++;
            }
        }
        if (!$attributeName) {
            $return = [];
            foreach (self::$attributesCache[$key] as $attributeName => $attributes) {
                $return = array_merge($return, $attributes);
            }
            return $return;
        }
        return self::$attributesCache[$key][$attributeName] ?? [];
    }

    /**
     * Returns first attribute instance for attribute name
     * @param string $attributeName
     * @return mixed
     * @throws \ReflectionException
     */
    public function getAttributeInstance(string $attributeName): mixed
    {
        return ReflectionClass::instance($this->reflectionClassName)->getAttributeInstanceForProperty(
            $this->name,
            $attributeName
        );
    }


    /**
     * @param string $attributeName
     * @return bool
     */
    public function hasAttribute(string $attributeName): bool
    {
        return !empty(count($this->getAttributes($attributeName)));
    }
}
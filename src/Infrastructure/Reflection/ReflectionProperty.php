<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;


class ReflectionProperty extends \ReflectionProperty
{
    protected static $typeCache = [];
    protected static array $attributesCache = [];
    private string $reflectionClassName;

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

    /**
     * @return bool Returns true if Type of one of the Types in UnionType allows null
     */
    public function allowsNull(): bool
    {
        $type = $this->getType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->allowsNull();
        } elseif ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType->allowsNull()) {
                    return true;
                }
            }
        }
        return false;
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
     * Returns first attribute instance for attribute name
     * @param string $attributeName
     * @param int $flags
     * @return mixed
     * @throws \ReflectionException
     */
    public function getAttributeInstance(string $attributeName, int $flags = 0): mixed
    {
        return ReflectionClass::instance($this->reflectionClassName)->getAttributeInstanceForProperty(
            $this->name,
            $attributeName,
            $flags
        );
    }

    /**
     * Returns true if Attribute is present on Property
     * @param string $attributeName
     * @param int $flags
     * @return bool
     */
    public function hasAttribute(string $attributeName, int $flags = 0): bool
    {
        return !empty(count($this->getAttributes($attributeName, $flags)));
    }

    /**
     * Cached getAttributes with full support for $attributeName and $flags
     *
     * @param string|null $attributeName
     * @param int $flags
     * @return ReflectionAttribute[]
     */
    public function getAttributes(?string $attributeName = null, int $flags = 0): array
    {
        $key = $this->reflectionClassName . '_' . $this->name;

        // Build raw attribute cache only once per property
        if (!isset(self::$attributesCache[$key])) {
            self::$attributesCache[$key] = [];
            $index = 0;

            // Collect all system attributes without filtering
            foreach (parent::getAttributes() as $systemAttribute) {
                // Wrap system attribute in custom ReflectionAttribute
                $attribute = new ReflectionAttribute(
                    $systemAttribute,
                    $this->reflectionClassName,
                    $this->name,
                    $index
                );

                self::$attributesCache[$key][] = $attribute;
                $index++;
            }
        }

        // Raw list of all attributes on this property
        $all = self::$attributesCache[$key];

        // No filters → return everything
        if ($attributeName === null && $flags === 0) {
            return $all;
        }

        $result = [];

        foreach ($all as $attribute) {
            $name = $attribute->getName();

            // No attribute name filter:
            // internal Reflection ignores flags if name is null
            if ($attributeName === null) {
                $result[] = $attribute;
                continue;
            }

            // INSTANCEOF flag enabled → inheritance-aware filtering
            if ($flags & \ReflectionAttribute::IS_INSTANCEOF) {
                if (is_a($name, $attributeName, true)) {
                    $result[] = $attribute;
                }
                continue;
            }

            // Exact class name match (default behavior)
            if ($name === $attributeName) {
                $result[] = $attribute;
            }
        }

        return $result;
    }
}
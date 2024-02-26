<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;


class ReflectionAttribute extends \ReflectionAttribute
{
    protected string $reflectionClassName;

    protected ?string $reflectionPropertyName = null;

    protected \ReflectionAttribute $attribute;

    protected int $attributeIndex = 0;

    public static $instanceCache = [];

    public function __construct(
        \ReflectionAttribute $attribute,
        string $reflectionClassName,
        string $reflectionPropertyName = null,
        int $attributeIndex = 0
    ) {
        $this->attribute = $attribute;
        $this->reflectionClassName = $reflectionClassName;
        $this->reflectionPropertyName = $reflectionPropertyName;
        $this->attributeIndex = $attributeIndex;
    }

    /**
     * @return string
     */
    public function getReflectionClassName(): string
    {
        return $this->reflectionClassName;
    }

    /**
     * @param string $reflectionClassName
     */
    public function setReflectionClassName(string $reflectionClassName): void
    {
        $this->reflectionClassName = $reflectionClassName;
    }

    /**
     * @return string
     */
    public function getReflectionPropertyName(): string
    {
        return $this->reflectionPropertyName;
    }

    /**
     * @param string $reflectionPropertyName
     */
    public function setReflectionPropertyName(string $reflectionPropertyName): void
    {
        $this->reflectionPropertyName = $reflectionPropertyName;
    }


    public function getReflectionClass(): ReflectionClass
    {
        return ReflectionClass::instance($this->reflectionClassName);
    }

    public function getRefflectionProperty(): ReflectionProperty
    {
        return self::getReflectionClass()->getProperty($this->reflectionPropertyName);
    }

    public function getArguments(): array
    {
        return $this->attribute->getArguments();
    }

    public function getName(): string
    {
        return $this->attribute->getName();
    }

    public function getTarget(): int
    {
        return $this->attribute->getTarget();
    }

    public function isRepeated(): bool
    {
        return $this->attribute->isRepeated();
    }

    public function __toString(): string
    {
        return $this->attribute . '';
    }

    public function newInstance(): object
    {
        $key = $this->reflectionClassName . '_' . ($this->reflectionPropertyName ?? '') . '_' . $this->attributeIndex;
        if (isset(self::$instanceCache[$key])) {
            return self::$instanceCache[$key];
        }
        $instance = $this->attribute->newInstance();
        if (method_exists($instance, 'setClassNameAttributeIsAttachedTo')) {
            $instance->setClassNameAttributeIsAttachedTo($this->reflectionClassName);
        }
        if (method_exists($instance, 'setPropertyNameAttributeIsAttachedTo') && $this->reflectionPropertyName) {
            $instance->setPropertyNameAttributeIsAttachedTo($this->reflectionPropertyName);
        }
        self::$instanceCache[$key] = $instance;
        return $instance;
    }
}
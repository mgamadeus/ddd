<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

class ReflectionNamedType extends \ReflectionNamedType
{
    public function __construct(private string $_name, private bool $_isBuiltIn = false, private bool $_allowsNull = true) {}

    public function allowsNull(): bool
    {
        return $this->_allowsNull;
    }

    public function isBuiltin(): bool
    {
        return $this->_isBuiltIn;
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    public function getName(): string
    {
        return $this->_name;
    }
}
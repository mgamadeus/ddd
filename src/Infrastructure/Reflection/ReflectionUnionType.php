<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

class ReflectionUnionType extends \ReflectionUnionType
{
    /** @var ReflectionNamedType[]|null */
    private ?array $reflectionNamedTypes = null;

    public function __construct(ReflectionNamedType &...$reflectionNamedTypes)
    {
        $this->reflectionNamedTypes = $reflectionNamedTypes;
    }

    /**
     * @return ReflectionNamedType[]
     */
    public function getTypes(): array
    {
        return $this->reflectionNamedTypes;
    }
}
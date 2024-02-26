<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\QueryOptions;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\QueryOptions\AppliedQueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;

#[Attribute]
class DtoQueryOptions extends ValueObject
{
    use BaseAttributeTrait;

    public string $baseEntity;

    public function __construct(string $baseEntity)
    {
        if (!class_exists($baseEntity)) {
            throw new InternalErrorException(
                "Defined Entity class {$baseEntity} to extract QueryOptions from does not exist."
            );
        }
        $this->baseEntity = $baseEntity;
        parent::__construct();
    }

    /**
     * Returns QueryOptions Attribute Instance from base Entity
     * @return QueryOptions|null
     * @throws ReflectionException
     */
    public function getQueryOptions(): ?AppliedQueryOptions
    {
        /** @var QueryOptionsTrait $baseEntityClass */
        $baseEntityClass = $this->baseEntity;
        /** @var QueryOptions|null $queryOptionsAttributeInstance */
        $baseReflectionClass = ReflectionClass::instance($this->baseEntity);
        if ($baseReflectionClass->hasTrait(QueryOptionsTrait::class)) {
            return $baseEntityClass::getDefaultQueryOptions();
        }
        return null;
    }
}


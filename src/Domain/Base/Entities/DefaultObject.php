<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Domain\Base\Entities\Traits\DefaultObjectTrait;
use DDD\Domain\Base\Entities\Traits\EntityTrait;
use DDD\Domain\Base\Entities\Traits\ValueObjectTrait;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\AfterConstruct\AfterConstructTrait;
use DDD\Infrastructure\Traits\ReflectorTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Traits\ValidatorTrait;
use ReflectionException;

abstract class DefaultObject extends BaseObject
{
    use SerializerTrait, ValidatorTrait, ParentChildrenTrait, AfterConstructTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait, DefaultObjectTrait;
}
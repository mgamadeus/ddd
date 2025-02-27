<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Traits\AfterConstruct\AfterConstructTrait;
use DDD\Infrastructure\Traits\ReflectorTrait;

abstract class RepoEntity
{
    use ReflectorTrait;
    use AfterConstructTrait;

    /**
     * This method has to map repository data to an Entity representation
     * @param bool $useEntityRegistryCache
     * @return Entity|null
     */
    abstract public function mapToEntity(bool $useEntityRegistryCache = true): DefaultObject|null;

    /**
     * This method has to map an Entity representation to repository and either update or create rows
     * @return Entity|null
     */
    abstract protected function mapToRepository(DefaultObject &$entity): bool;
}
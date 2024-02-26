<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Services;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DatabaseRepoEntitySet;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AppService;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * General Service for Entities
 */
class EntitiesService extends Service
{
    public const DEFAULT_ENTITY_CLASS = null;

    /**
     * @param string|int|null $accountId
     * @param string|int|null $entityId
     * @param bool $useEntityRegistrCache
     * @return Entity|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $repoClassInstance = $this->getEntityRepoClass();
        if (!$repoClassInstance)
            return null;

        $enityInstance = $repoClassInstance->find($entityId, $useEntityRegistrCache);
        if (!$enityInstance && $this->throwErrors) {
            /** @var Entity $entityClass */
            $entityClass = self::DEFAULT_ENTITY_CLASS;
            $classWithNamespace = $entityClass::getReflectionClass()->getClassWithNamespace();
            throw new NotFoundException(
                "{$classWithNamespace->name} not found or current authenticated Account is not authorized to access it"
            );
        }
        return $enityInstance;
    }

    /**
     * Returns all Elements as EntitySet
     * @param int|null $offset
     * @param $limit
     * @param bool $useEntityRegistrCache
     * @return EntitySet|null
     */
    public function findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true):?EntitySet {
        $repoClassInstance = $this->getEntitySetRepoClass();
        if (!$repoClassInstance)
            return null;
        $queryBuilder = $repoClassInstance::createQueryBuilder();
        if ($offset !== null)
            $queryBuilder->setFirstResult($offset);
        if ($limit !== null)
            $queryBuilder->setMaxResults($limit);
        return $repoClassInstance->find($queryBuilder, $useEntityRegistrCache);
    }

    /**
     * Updates entity
     * @param Entity $entity
     * @return Entity
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function update(
        Entity $entity
    ): Entity {
        $repoClass = $entity::getRepoClassInstance();
        return $repoClass->update($entity);
    }

    /**
     * Deletes entity
     * @param Entity $entity
     * @return void
     * @throws BadRequestException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function delete(
        Entity $entity
    ): void {
        $repoClass = $entity::getRepoClassInstance();
        $repoClass->delete($entity);
    }

    /**
     * @return Entity|null returns an instance of the particular Entity class
     */
    public static function getEntityClassInstance(): ?Entity
    {
        if (!static::DEFAULT_ENTITY_CLASS) {
            return null;
        }
        $entityClassName = static::DEFAULT_ENTITY_CLASS;
        /** @var Entity $entityClassInstance */
        return $entityClassName::newInstance();
    }

    /**
     * Returns Entity Repo Class
     * @return DatabaseRepoEntity|null
     */
    public function getEntityRepoClass(): ?DatabaseRepoEntity{
        if (!static::DEFAULT_ENTITY_CLASS) {
            return null;
        }
        /** @var Entity $entityClass */
        $entityClass = AppService::instance()->getContainerServiceClassNameForClass(
            (string)static::DEFAULT_ENTITY_CLASS
        );
        /** @var  $repoClassInstance */
        $repoClassInstance = $entityClass::getRepoClassInstance();
        if (!$repoClassInstance) {
            return null;
        }
        return $repoClassInstance;
    }

    /**
     * Returns EntitySet Repo Class
     * @return DatabaseRepoEntity|null
     */
    public function getEntitySetRepoClass(): ?DatabaseRepoEntitySet{
        if (!static::DEFAULT_ENTITY_CLASS) {
            return null;
        }
        /** @var Entity $entityClass */
        $entityClass = AppService::instance()->getContainerServiceClassNameForClass(
            (string)static::DEFAULT_ENTITY_CLASS
        );
        /** @var EntitySet $entitySetClass */
        $entitySetClass = $entityClass::getEntitySetClass();
        if (!$entitySetClass)
            return null;
        /** @var DatabaseRepoEntitySet $repoClassInstance */
        $repoClassInstance = $entitySetClass::getRepoClassInstance();
        return $repoClassInstance;
    }
}
<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Services;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DatabaseRepoEntitySet;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Exception;
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
     * Loads a single entity by id, with two distinct execution paths:
     *
     *  ── Eager path (Set-Find delegation) ──────────────────────────────────
     *  Activates when the entity class's default QueryOptions carries expand
     *  options — i.e. when a controller (or other caller) has already configured
     *  expansion via `EntityClass::getDefaultQueryOptions()->setQueryOptionsFromRequestDto(...)`
     *  or an equivalent setter, and the entity-set repo is wired.
     *
     *  Instead of `$repo->find($id)` followed by N lazy-loads through
     *  `$entity->expand()`, this path builds an entity-set query with
     *  `<rootAlias>.id = :find_id` and routes through `DBEntitySet::find()`,
     *  which calls `applyExpandOptionsToDoctrineQueryBuilder()` and materializes
     *  every db-loadable expand-property as a LEFT JOIN in a single SQL query.
     *  Read rights of expanded entities are applied in the same query (including
     *  multi-level join chains and the null-safe wrap for optional expansions).
     *
     *  Because `applyQueryOptions()` reads from the entity-SET class's default
     *  QueryOptions, this method mirrors the entity class's default options onto
     *  the set class for the duration of the find:
     *    1. captures the set class's current default options (reference to the
     *       static-cached AppliedQueryOptions instance);
     *    2. sets a clone of the entity class's default options as the new set
     *       default — the clone is shallow, but inner ExpandOption mutations
     *       (joinAlias rewriting during query build) are part of the framework's
     *       normal lifecycle and recomputed per build, so the share is harmless;
     *    3. runs the set find;
     *    4. restores the original set default in `finally` to prevent leaking
     *       expand state across subsequent finds within the same request.
     *  The first element of the resulting EntitySet is returned (or null if no
     *  match — the `<alias>.id = :find_id` filter guarantees at most one root).
     *
     *  After this method returns, callers typically still invoke
     *  `$entity->expand()`. With the default fill-in-the-gaps semantics in
     *  `QueryOptionsTrait::expand()`, that call is a no-op for db-loaded
     *  properties and only triggers lazy-loads for non-db lazy types
     *  (CLASS_METHOD, VIRTUAL — e.g. SupportMessageAttachment.authJWTPayload),
     *  preserving the eager work done here.
     *
     *  ── Direct path (Repo find) ───────────────────────────────────────────
     *  Activates when no expand is configured, the entity-set repo isn't wired,
     *  the entity-set class lacks QueryOptionsTrait, or `$entityId` is null.
     *  Falls through to `$repo->find($id, $useEntityRegistrCache)` — identical
     *  to the pre-change behavior; lazy-load and registry-cache semantics are
     *  preserved unchanged for callers that don't ask for eager expansion.
     *
     *  ── Common tail ───────────────────────────────────────────────────────
     *  In either path, when `$this->throwErrors` is set and the result is null,
     *  a `NotFoundException` is raised with a message that conflates
     *  "not found" and "not authorized" (rights-restrictions can hide rows the
     *  caller has no read access to).
     *
     * @param string|int|null $entityId Primary key of the entity to load. `null`
     *   skips the eager path and delegates to the direct repo find (which
     *   typically returns null on its own).
     * @param bool $useEntityRegistrCache Forwarded to both paths. When true (the
     *   default), the entity registry cache is consulted; the eager path's set
     *   find caches the result under the set repo's QB hash, the direct path
     *   under the entity repo's QB hash. Cache slots are not shared between the
     *   two paths.
     * @return Entity|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $repoClassInstance = $this->getEntityRepoClassInstance();
        if (!$repoClassInstance) {
            return null;
        }
        /** @var Entity $entityClass */
        $entityClass = static::DEFAULT_ENTITY_CLASS;

        $entityInstance = null;
        $eagerPathTaken = false;

        // Eager path: when the entity's default QueryOptions has expand options set
        // (typically because a controller called setQueryOptionsFromRequestDto on
        // the entity class), route through DBEntitySet::find with an
        // `<rootAlias>.id = :find_id` filter so all expand-properties resolve as
        // LEFT JOINs in a single SQL query — instead of 1+N (single-find followed
        // by per-property lazy-load in $entity->expand()).
        //
        // Falls back to the direct repo find when no expand is set, no entity-set
        // repo is wired, or $entityId is null.
        if ($entityId !== null && $entityClass && method_exists($entityClass, 'getDefaultQueryOptions')) {
            $expandOptions = $entityClass::getDefaultQueryOptions()->getExpandOptions();
            if ($expandOptions && $expandOptions->count() > 0) {
                $setRepoInstance = $this->getEntitySetRepoClassInstance();
                if ($setRepoInstance) {
                    $setRepoClass = get_class($setRepoInstance);
                    /** @var EntitySet|string $entitySetClass */
                    $entitySetClass = $setRepoClass::BASE_ENTITY_SET_CLASS;
                    if ($entitySetClass && method_exists($entitySetClass, 'getDefaultQueryOptions')) {
                        $alias = $repoClassInstance::getBaseModelAlias();

                        // Mirror the entity's default QueryOptions onto the entity-set
                        // class so DBEntitySet::find -> applyQueryOptions picks up the
                        // expand tree (set-find reads its options from the set class).
                        // The shallow clone shares inner ExpandOption objects with the
                        // entity's default — this is consistent with the framework's
                        // normal lifecycle (joinAlias gets recomputed on each build).
                        // The set's default is restored in finally to avoid leaking
                        // expand state across subsequent finds.
                        $originalSetOptions = $entitySetClass::getDefaultQueryOptions();
                        $mirroredOptions = clone $entityClass::getDefaultQueryOptions();
                        /** @var QueryOptionsTrait $entitySetClass */
                        $entitySetClass::setDefaultQueryOptions($mirroredOptions);

                        try {
                            $queryBuilder = $setRepoClass::createQueryBuilder(true)
                                ->andWhere("{$alias}.id = :find_id")
                                ->setParameter('find_id', $entityId);
                            $entitySet = $setRepoInstance->find($queryBuilder, $useEntityRegistrCache);
                            $entityInstance = $entitySet?->first();
                        } finally {
                            $entitySetClass::setDefaultQueryOptions($originalSetOptions);
                        }
                        $eagerPathTaken = true;
                    }
                }
            }
        }

        // Direct path: no expand options on the entity's default QueryOptions, no
        // entity-set repo wired, or $entityId is null. Identical to the pre-change
        // behavior — keeps lazy-load and registry-cache semantics for callers that
        // don't ask for eager expansion.
        if (!$eagerPathTaken) {
            $entityInstance = $repoClassInstance->find($entityId, $useEntityRegistrCache);
        }

        if (!$entityInstance && $this->throwErrors) {
            $classWithNamespace = $entityClass::getReflectionClass()->getClassWithNamespace();
            throw new NotFoundException(
                "{$classWithNamespace->name} not found or current authenticated Account is not authorized to access it"
            );
        }
        return $entityInstance;
    }

    /**
     * Returns all Elements as EntitySet
     * @param int|null $offset
     * @param $limit
     * @param bool $useEntityRegistrCache
     * @return EntitySet|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAll(?int $offset = null, ?int $limit = null, bool $useEntityRegistrCache = true): ?EntitySet
    {
        $repoClassInstance = $this->getEntitySetRepoClassInstance();
        if (!$repoClassInstance) {
            return null;
        }
        $queryBuilder = $repoClassInstance::createQueryBuilder();
        if ($offset !== null) {
            $queryBuilder->setFirstResult($offset);
        }
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }
        return $repoClassInstance->find($queryBuilder, $useEntityRegistrCache);
    }

    /**
     * Returns the count of all elements matching the current QueryOptions (filters + expand) and read rights.
     *
     * @return int
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function countAll(): int
    {
        $repoClassInstance = $this->getEntitySetRepoClassInstance();
        if (!$repoClassInstance) {
            return 0;
        }
        return $repoClassInstance->count();
    }

    /**
     * Updates entity
     * @param DefaultObject $entity
     * @return DefaultObject
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function update(
        DefaultObject $entity,
        int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH
    ): DefaultObject {
        $repoClass = $entity::getRepoClassInstance();
        return $repoClass->update($entity, $depth);
    }

    /**
     * High performance Batch update method:
     * It does not return ids of updated Entities, it does not adapt translations if required, it is not updating recurively,
     * best used in data import scenarios
     *
     * @param EntitySet $entitySet
     * @param bool $useInsertIgnore
     * @return void
     * @throws Exception
     */
    public function batchUpdate(EntitySet &$entitySet, bool $useInsertIgnore = false): void
    {
        $repoClass = $entitySet::getRepoClassInstance();
        $repoClass->batchUpdate($entitySet, $useInsertIgnore);
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
    public function getEntityRepoClassInstance(): ?DatabaseRepoEntity
    {
        if (!static::DEFAULT_ENTITY_CLASS) {
            return null;
        }
        /** @var Entity $entityClass */
        $entityClass = DDDService::instance()->getContainerServiceClassNameForClass(
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
    public function getEntitySetRepoClassInstance(): ?DatabaseRepoEntitySet
    {
        if (!static::DEFAULT_ENTITY_CLASS) {
            return null;
        }
        /** @var Entity $entityClass */
        $entityClass = DDDService::instance()->getContainerServiceClassNameForClass(
            (string)static::DEFAULT_ENTITY_CLASS
        );
        /** @var EntitySet $entitySetClass */
        $entitySetClass = $entityClass::getEntitySetClass();
        if (!$entitySetClass) {
            return null;
        }
        /** @var DatabaseRepoEntitySet $repoClassInstance */
        $repoClassInstance = $entitySetClass::getRepoClassInstance();
        return $repoClassInstance;
    }
}
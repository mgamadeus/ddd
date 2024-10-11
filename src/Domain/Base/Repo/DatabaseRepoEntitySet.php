<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Traits\ReflectorTrait;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Mapping\MappingException;

abstract class DatabaseRepoEntitySet
{
    use ReflectorTrait;

    public const BASE_REPO_CLASS = null;
    public const BASE_ENTITY_SET_CLASS = null;

    /**
     * @return string Returns base model class name
     */
    public static function getBaseModel(): string
    {
        $baseRepoClass = static::BASE_REPO_CLASS;
        $baseEntityClass = $baseRepoClass::BASE_ENTITY_CLASS;
        return $baseRepoClass::BASE_ORM_MODEL;
    }

    /**
     * @return string Returns base model table alias
     */
    public static function getBaseModelAlias(): string
    {
        /** @var DoctrineModel $baseModelClass */
        $baseModelClass = static::getBaseModel();
        return $baseModelClass::MODEL_ALIAS;
    }

    /**
     * This function can be overwritten
     * sometimes processing after mapping is needed, as e.g. we load something from cache, e.g. ProjectSetting
     * and we need to add something there, e.g. Rights DirectorySettings from Account, this should not be cached, as it could be
     * that another account accesses the same project with a different set of rights...
     * @param Entity|EntitySet|null $entity
     * @return Entity|EntitySet|null
     */
    public function postProcessAfterMapping(
        DefaultObject|null &$entity
    ): DefaultObject|null {
        return $entity;
    }

    /**
     * Returns EntitySet containing all Elemewnts corresponding to QueryBuilder ø(if present)
     * @param DoctrineQueryBuilder|null $queryBuilder
     * @param bool $useEntityRegistrCache
     * @param array $initiatorClasses
     * @return EntitySet|null
     */
    abstract public function find(
        ?DoctrineQueryBuilder $queryBuilder = null,
        bool $useEntityRegistrCache = true,
        array $initiatorClasses = []
    ): ?EntitySet;

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
        $doctrineModels = [];
        $baseRepoClass = self::BASE_REPO_CLASS;
        foreach ($entitySet->getElements() as $entity) {
            /** @var DatabaseRepoEntity $dbEntity */
            $dbEntity = new (static::BASE_REPO_CLASS)();
            if ($dbEntity->mapToRepository($entity)) {
                $doctrineModels[] = $dbEntity->getOrmInstance();
            }
        }
        $entityManager = EntityManagerFactory::getInstance();
        $entityManager->upsertMultiple($doctrineModels, $useInsertIgnore);
    }
}
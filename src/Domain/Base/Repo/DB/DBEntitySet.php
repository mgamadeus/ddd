<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\AppliedQueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DatabaseRepoEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\ReflectorTrait;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query\Expr\From;
use Doctrine\ORM\QueryBuilder;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

abstract class DBEntitySet extends DatabaseRepoEntitySet
{
    use ReflectorTrait;

    public const BASE_REPO_CLASS = null;
    public const BASE_ENTITY_SET_CLASS = null;

    /**
     * Applies QueryOptions to QueryBuilder
     * @param QueryBuilder $queryBuilder
     * @return QueryBuilder
     */
    public static function applyQueryOptions(DoctrineQueryBuilder &$queryBuilder): DoctrineQueryBuilder
    {
        $entitySetClass = (string)static::BASE_ENTITY_SET_CLASS;
        $entitySetReflectionClass = ReflectionClass::instance($entitySetClass);
        if (!$entitySetReflectionClass->hasTrait(QueryOptionsTrait::class)) {
            return $queryBuilder;
        }
        /** @var QueryOptionsTrait $entitySetClass */
        /** @var AppliedQueryOptions $defaultQueryOptions */
        $defaultQueryOptions = $entitySetClass::getDefaultQueryOptions();
        if (!$queryBuilder->getMaxResults() && $defaultQueryOptions->getTop()) {
            $queryBuilder->setMaxResults($defaultQueryOptions->getTop());
        }
        if (!$queryBuilder->getFirstResult() && $defaultQueryOptions->getSkip()) {
            $queryBuilder->setFirstResult($defaultQueryOptions->getSkip());
        }
        // for expand options on entities in set (we want to load them with a join implicitly)
        if ($expandOptions = $defaultQueryOptions->getExpandOptions()) {
            $expandOptions->applyExpandOptionsToDoctrineQueryBuilder($queryBuilder, static::getBaseModel());
        }
        if ($filters = $defaultQueryOptions->getFilters()) {
            $filters->applyFiltersToDoctrineQueryBuilder(
                queryBuilder: $queryBuilder,
                baseModelClass: self::getBaseModel()
            );
        }
        if ($orderBy = $defaultQueryOptions->getOrderBy()) {
            $orderBy->applyOrderByToDoctrineQueryBuilder(
                queryBuilder: $queryBuilder,
                baseModelClass: self::getBaseModel()
            );
        }
        if ($select = $defaultQueryOptions->getSelect()) {

            $select->applySelectToDoctrineQueryBuilder(
                queryBuilder: $queryBuilder,
                baseModelClass: self::getBaseModel()
            );
        }
        return $queryBuilder;
    }

    /**
     * Finds elements either by queryBuilder query and returns EntitySet
     * @param DoctrineQueryBuilder|null $queryBuilder
     * @param $useEntityRegistrCache
     * @param array $initiatorClasses
     * @return EntitySet|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function find(
        ?DoctrineQueryBuilder $queryBuilder = null,
        bool $useEntityRegistrCache = true,
        array $initiatorClasses = []
    ): ?EntitySet
    {
        if (!$this::BASE_REPO_CLASS) {
            throw new InternalErrorException('No BASE_REPO_CLASS defined in ' . static::class);
        }
        if (!$this::BASE_ENTITY_SET_CLASS) {
            throw new InternalErrorException('No BASE_ENTITY_SET_CLASS defined in ' . static::class);
        }
        /** @var DBEntity $baseRepoClass */
        $baseRepoClass = $this::BASE_REPO_CLASS;
        $baseEntityClass = $baseRepoClass::BASE_ENTITY_CLASS;
        $baseEntitySetClass = $this::BASE_ENTITY_SET_CLASS;
        $baseOrmModel = $baseRepoClass::BASE_ORM_MODEL;
        $baseOrmModelAlias = $baseOrmModel::MODEL_ALIAS;

        $entityRegistry = DoctrineEntityRegistry::getInstance();

        if (!$queryBuilder) {
            $queryBuilder = EntityManagerFactory::getInstance()->createQueryBuilder();
        }

        $skipSelectFrom = false;
        // in case we define a join, the select from part needs to be added before the join
        // cause otherwise stupid doctrine throws an error. In case the select from is added before
        // it cannot be added twice, cause doctrine throws another supid error
        foreach ($queryBuilder->getDQLPart('from') as $fromPart) {
            /** @var From $fromPart */
            if ($fromPart->getFrom() == $baseOrmModel) {
                $skipSelectFrom = true;
            }
        }
        if (!$skipSelectFrom) {
            // we apply the select and from clause based on model and alias definitions
            $queryBuilder->addSelect($baseOrmModelAlias)->from($baseOrmModel, $baseOrmModelAlias);
        }

        // We apply the restrictions of the readRightsQuery
        /** @var DoctrineQueryBuilder $queryBuilder */
        $baseRepoClass::applyReadRightsQuery($queryBuilder);

        //handle translations
        $queryBuilder = $baseRepoClass::applyTranslationJoinToQueryBuilder($queryBuilder);

        // We apply query options
        $queryBuilder = self::applyQueryOptions($queryBuilder);

        if ($useEntityRegistrCache) {
            $className = (string)$this::BASE_ENTITY_SET_CLASS;
            // we check if an element exists in the registry
            $entitySetInstance = $entityRegistry->get(static::class, $queryBuilder);
            if ($entitySetInstance) {
                // we need to restore parent / child relationShips as we do not serialize them
                $entitySetInstance->addChildren(...$entitySetInstance->getElements());
                return $this->postProcessAfterMapping($entitySetInstance);
            }
        }

        /*echo $queryBuilder->getQuery()->getSQL()."\n<br />";
        foreach ($queryBuilder->getParameters() as $parameter){
            $parameterValue = is_array($parameter->getValue())? implode(', ', $parameter->getValue()): $parameter->getValue();
            echo $parameter->getName() . ' ' . $parameterValue ."\n";
        }
        echo "\n<br />\n<br />";*/
        $ormInstances = $queryBuilder->applyDistinctSubqueryIfNeededAndGetResult();

        /** @var EntitySet $entitySetInstance */
        $entitySetInstance = new $baseEntitySetClass();
        $memoryUsage = memory_get_usage();
        foreach ($ormInstances as $ormInstance) {
            /** @var DBEntity $baseRepoInstance */
            $baseRepoInstance = new $baseRepoClass();

            // in cases of queries like
            // $queryBuilder->addSelect("MATCH({$searchField},{$searchFieldInverted}) AGAINST (:search_string in boolean mode) as relevance")
            // we receive the ormInstance in an array of [0 => DoctrineModel, 'relevance' => 1.234]
            if (is_array($ormInstance)) {
                $ormInstance = $ormInstance[0];
            }
            $entityInstance = $baseRepoInstance->find(
                $ormInstance->id,
                $useEntityRegistrCache,
                $ormInstance,
                true,
                $initiatorClasses
            );
            if ($entityInstance) {
                $entitySetInstance->add($entityInstance);
            }
            $memoryUsage = memory_get_usage();
        }
        $memoryUsage = memory_get_usage();
        $entityRegistry->add($entitySetInstance, static::class, $queryBuilder, true);
        // since we load many instances and call find on their repo with loaded OrmInstance we defer cache commit to the end
        $entityRegistry::commit();
        // Entity Manager's unit of work cache of various types especially loaded DoctrineModels can end up using
        // the whole allocated memory, so if the memory usage is high, we clear it
        if (DDDService::instance()->isMemoryUsageHigh()) {
            //echo (memory_get_usage() / AppService::getMemoryLimitInBytes() *100) . "% \n";
            //echo "Clear Memory Usage \n";
            //echo (memory_get_usage() / AppService::getMemoryLimitInBytes() *100) . "% \n";
            EntityManagerFactory::clearAllInstanceCaches();
        }
        return $this->postProcessAfterMapping($entitySetInstance);
    }

    /**
     * Update each Entity in given EntitySet and then return it back
     * @param EntitySet $entitySet
     * @param int $depth
     * @return EntitySet
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function update(EntitySet $entitySet, int $depth = 1): EntitySet
    {
        foreach ($entitySet->getElements() as &$entity) {
            $repoClass = $entity::getRepoClass(LazyLoadRepo::DB);
            /** @var DBEntity $repo */
            $repo = $repoClass && class_exists($repoClass) ? new $repoClass() : null;
            if ($repo) {
                if (method_exists($repo, 'update')) {
                    $entity = $repo->update($entity, $depth);
                }
            }
        }
        return $entitySet;
    }

    /**
     * Deletes each Entity in given EntitySet
     * @param EntitySet $entitySet
     * @return void
     * @throws BadRequestException
     * @throws ReflectionException
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws OptimisticLockException
     */
    public function delete(EntitySet &$entitySet): void
    {
        foreach ($entitySet->getElements() as &$entity) {
            $repoClass = $entity::getRepoClass(LazyLoadRepo::DB);
            /** @var DBEntity $repo */
            $repo = $repoClass && class_exists($repoClass) ? new $repoClass() : null;
            if ($repo) {
                if (method_exists($repo, 'delete')) {
                    $repo->delete($entity);
                }
            }
        }
    }

    /**
     * Shorthand method for creation of a DoctrineQueryBuilder for internal use
     * @param bool $includeModelSelectFromClause
     * @return DoctrineQueryBuilder
     */
    public static function createQueryBuilder(bool $includeModelSelectFromClause = false): DoctrineQueryBuilder
    {
        /** @var DBEntity $baseRepoClass */
        $baseRepoClass = static::BASE_REPO_CLASS;
        return $baseRepoClass::createQueryBuilder($includeModelSelectFromClause);
    }

    public static function getQueryBuilderForLazyload(
        string $baseRepoEntityOrEntitySetClassName,
        DefaultObject &$initiatingEntity,
        LazyLoad $lazyloadAttributeInstance
    ): ?QueryBuilder
    {
        /** @var DBEntity|DBEntitySet $baseRepoEntityOrEntitySetClassName */
        if (!$baseRepoEntityOrEntitySetClassName) {
            $baseRepoEntityOrEntitySetClassName = static::BASE_REPO_CLASS;
        }
        // this function can be called within a DBEntity or DBEntitySet, we need to handle both cases
        $baseRepoClass = is_a($baseRepoEntityOrEntitySetClassName, DBEntitySet::class, true) ? $baseRepoEntityOrEntitySetClassName::BASE_REPO_CLASS : $baseRepoEntityOrEntitySetClassName;
        $baseEntityClass = $baseRepoClass::BASE_ENTITY_CLASS;
        $baseEntityReflectionClass = ReflectionClass::instance($baseEntityClass);
        $queryBuilder = $baseRepoEntityOrEntitySetClassName::createQueryBuilder(true);

        $whereClausesApplied = false;
        // we load through an intermediary n-n table
        if ($lazyloadAttributeInstance->loadThrough) {
            /** @var EntitySet $loadThroughEntitySetClass */
            $loadThroughEntitySetClass = $lazyloadAttributeInstance->loadThrough;
            /** @var Entity $loadThroughClass */
            $loadThroughClass = $loadThroughEntitySetClass::getEntityClass();
            /** @var Entity $loadThroughClass */
            $loadThroughReflectionClass = ReflectionClass::instance((string)$loadThroughClass);
            /** @var DBEntity $loadThroughRepoClass */
            $loadThroughRepoClass = $loadThroughClass::getRepoClass(LazyLoadRepo::DB);
            /** @var DoctrineModel $loadThroughBaseModel */
            $loadThroughBaseModel = $loadThroughRepoClass::BASE_ORM_MODEL;
            $loadThroughBaseModelAlias = $loadThroughBaseModel::MODEL_ALIAS;

            // we need to find the property in the current table, that references the $loadThrough EntitySet class
            $propertyReferencingJoinTable = null;
            foreach ($baseEntityReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (
                    $property->getType() instanceof ReflectionNamedType and is_a(
                        $loadThroughEntitySetClass,
                        $property->getType()->getName(),
                        true
                    )
                ) {
                    $propertyReferencingJoinTable = $property->getName();
                    break;
                }
            }
            if ($propertyReferencingJoinTable) {
                foreach ($loadThroughReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                    // we find the property in the Join table is of the same type as the initiatingEntity, and then we try to find a property named
                    // with a prostFix 'Id'
                    if (
                        $property->getType() instanceof ReflectionNamedType and is_a(
                            $initiatingEntity::class,
                            $property->getType()->getName(),
                            true
                        ) && $loadThroughReflectionClass->hasProperty($property->getName() . 'Id')
                    ) {
                        $foreignKey = $property->getName() . 'Id';
                        $queryBuilder->leftJoin(
                            $baseRepoEntityOrEntitySetClassName::getBaseModelAlias() . '.' . $propertyReferencingJoinTable,
                            $loadThroughBaseModelAlias
                        )->andWhere("{$loadThroughBaseModelAlias}.$foreignKey = :foreign_key_id")->setParameter(
                            'foreign_key_id',
                            $initiatingEntity->id
                        );
                        $whereClausesApplied = true;
                        break;
                    }
                }
            }
        } else {
            foreach ($baseEntityReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                // if initiating entity is of a type that we have also in our basee entity and we have an id of this as property,
                // we filter for the id of the initiating entity so we load all dependent entities of it
                if (
                    $property->getType() instanceof ReflectionNamedType and is_a(
                        $initiatingEntity::class,
                        $property->getType()->getName(),
                        true
                    ) && $baseEntityReflectionClass->hasProperty($property->getName() . 'Id')
                ) {
                    $foreignKey = $property->getName() . 'Id';
                    $baseModelAlias = $baseRepoEntityOrEntitySetClassName::getBaseModelAlias();
                    $queryBuilder->andWhere("{$baseModelAlias}.$foreignKey = :foreign_key_id")->setParameter(
                        'foreign_key_id',
                        $initiatingEntity->id
                    );
                    $whereClausesApplied = true;
                    break;
                }
            }
        }
        if (!$whereClausesApplied) {
            return null;
        }
        return $queryBuilder;
    }

    /**
     * lazy loads dependent entity by propertyName + Id
     * @param DefaultObject $initiatingEntity
     * @param LazyLoad $lazyloadAttributeInstance
     * @return DefaultObject|null
     */
    public function lazyload(
        DefaultObject &$initiatingEntity,
        LazyLoad &$lazyloadAttributeInstance
    ): ?EntitySet
    {
        $queryBuilder = self::getQueryBuilderForLazyload(static::class, $initiatingEntity, $lazyloadAttributeInstance);
        return $this->find($queryBuilder, $lazyloadAttributeInstance->useCache);
    }
}
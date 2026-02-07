<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo;

use DDD\Domain\Base\Entities\Attributes\NoRecursiveUpdate;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\Attributes\DatabaseTranslation;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionAttribute;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\From;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\Mapping\MappingException;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

abstract class DatabaseRepoEntity extends RepoEntity
{
    /** @var string */
    public const BASE_ENTITY_CLASS = null;

    /** @var string */
    public const BASE_ORM_MODEL = null;

    protected static $applyRightsRestrictions = true;

    /**
     * @var bool defines if the class loads a singe row or multiple rows, e.g. some site_settings are stored
     * on multiple rows, e.g. opening_hours, opening_hour_notes etc.
     */
    public bool $isMultiRowEntity = false;

    /** @var DoctrineModel|DoctrineModel[]|null model storing all the loaded data */
    protected DoctrineModel|array|null $ormInstance;

    public static function getApplyRightsRestrictions(): bool
    {
        return self::$applyRightsRestrictions;
    }

    public static function setApplyRightsRestrictions(bool $applyRightsRestrictions): void
    {
        self::$applyRightsRestrictions = $applyRightsRestrictions;
    }

    public static function extractBool(mixed $value): bool
    {
        if (is_string($value)) {
            return $value == 'true' || $value == '1' || $value == 'y' || $value == 'j';
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int)$value) >= 1;
        }
        return false;
    }

    /**
     * Returns DoctrineModel class name for Entity instance
     * @param Entity $entity
     * @return string
     */
    public static function getBaseModelNameForEntityInstance(DefaultObject &$entity): string
    {
        if ($modelClassName = (StaticRegistry::$modelNamesForEntityClasses[$entity::class] ?? null)) {
            return $modelClassName;
        }
        $entityClassWithNamespace = $entity::getClassWithNamespace();
        StaticRegistry::$modelNamesForEntityClasses[$entity::class] = DatabaseModel::getModelClassWithNamespaceForEntityClassWithNamespace(
            $entityClassWithNamespace
        )->getNameWithNamespace();
        return StaticRegistry::$modelNamesForEntityClasses[$entity::class];
    }

    /**
     * lazy loads dependent entity by propertyName + Id
     * @param DefaultObject $initiatingEntity
     * @param LazyLoad $lazyloadAttributeInstance
     * @return DefaultObject|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function lazyload(
        DefaultObject &$initiatingEntity,
        LazyLoad &$lazyloadAttributeInstance
    ): ?DefaultObject {
        $propertyContainingId = $lazyloadAttributeInstance->getPropertyContainingId();
        $selfID = $initiatingEntity->$propertyContainingId;
        if (!$selfID) {
            return null;
        }
        return $this->find($selfID, $lazyloadAttributeInstance->useCache);
    }

    /**
     * Finds element either by id or by queryBuilder query and returns Entity
     * @param DoctrineQueryBuilder|string|int $idOrQueryBuilder
     * @param bool $useEntityRegistrCache
     * @param DoctrineModel|null $loadedOrmInstance
     * @param bool $deferredCaching
     * @param array $initiatorClasses
     * @return DefaultObject|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function find(
        DoctrineQueryBuilder|string|int $idOrQueryBuilder,
        bool $useEntityRegistrCache = true,
        ?DoctrineModel &$loadedOrmInstance = null,
        bool $deferredCaching = false,
        array $initiatorClasses = []
    ): ?DefaultObject {
        if (!$this::BASE_ENTITY_CLASS) {
            throw new InternalErrorException('No BASE_ENTITY_CLASS defined in ' . static::class);
        }
        if (!$this::BASE_ORM_MODEL) {
            throw new InternalErrorException('No BASE_ORM_MODEL defined in ' . static::class);
        }
        $useEntityRegistrCache = $useEntityRegistrCache && !DoctrineEntityRegistry::$clearCache;

        $entityRegistry = DoctrineEntityRegistry::getInstance();

        $queryBuilder = $this->buildFindQueryBuilder($idOrQueryBuilder);

        if ($useEntityRegistrCache) {
            // Check if an element exists in the registry.
            $entityInstance = $entityRegistry->get(static::class, $queryBuilder);
            if ($entityInstance) {
                return $this->postProcessAfterMapping($entityInstance);
            }
        }

        // if loaded Orm Instance is passed, object is not laoded but taken from isntance instead
        if ($loadedOrmInstance) {
            $ormInstance = $loadedOrmInstance;
        } elseif ($this->isMultiRowEntity) {
            // in case of multi row entities, we get a result list instead of a single row
            $query = $queryBuilder->getQuery();
            if (!$useEntityRegistrCache) {
                $query->disableResultCache()->useQueryCache(false)->setHint(Query::HINT_REFRESH, true);
            }
            $ormInstance = $query->getResult();
        } else {
            $query = $queryBuilder->getQuery();
            if (!$useEntityRegistrCache) {
                $query->disableResultCache()->useQueryCache(false)->setHint(Query::HINT_REFRESH, true);
            }
            $ormInstance = $query->setMaxResults(1)->getResult();
            /*echo $queryBuilder->getQuery()->getSQL()."\n<br />";
            foreach ($queryBuilder->getParameters() as $parameter){
                $parameterValue = is_array($parameter->getValue())? implode(', ', $parameter->getValue()): $parameter->getValue();
                echo $parameter->getName() . ' ' . $parameterValue ."\n";
            }
            echo "\n<br />\n<br />";*/
            $ormInstance = $ormInstance[0] ?? null;
        }
        if (!$ormInstance) {
            // store empty null result
            if ($useEntityRegistrCache) {
                $null = null;
                $entityRegistry->add($null, static::class, $queryBuilder, $deferredCaching);
            }
            return null;
        }

        $this->ormInstance = $ormInstance;
        $entityInstance = $this->mapToEntity($useEntityRegistrCache, $initiatorClasses);
        $entityRegistry->add($entityInstance, static::class, $queryBuilder, $deferredCaching);
        //}
        // Entity Manager's unit of work cache of various types especially loaded DoctrineModels can end up using
        // the whole allocated memory, so if the memory usage is high, we clear it
        if (DDDService::instance()->isMemoryUsageHigh()) {
            EntityManagerFactory::clearAllInstanceCaches();
        }
        // post processing needs to happen after storage!!!
        return $this->postProcessAfterMapping($entityInstance);
    }

    /**
     * Builds a QueryBuilder with the same structure as find() uses, including select/from, read rights,
     * translations, and default query options. This ensures the QueryBuilder hash matches exactly what
     * find() would produce, which is critical for cache invalidation after delete.
     *
     * @param DoctrineQueryBuilder|string|int $idOrQueryBuilder An entity ID or pre-configured QueryBuilder
     * @return DoctrineQueryBuilder
     * @throws ReflectionException
     */
    protected function buildFindQueryBuilder(DoctrineQueryBuilder|string|int $idOrQueryBuilder): DoctrineQueryBuilder
    {
        $baseOrmModelAlias = (static::BASE_ORM_MODEL)::MODEL_ALIAS;

        if (!($idOrQueryBuilder instanceof DoctrineQueryBuilder)) {
            $queryBuilder = EntityManagerFactory::getInstance()->createQueryBuilder();
            // apply id query
            $queryBuilder->andWhere($baseOrmModelAlias . '.id = :find_id')->setParameter('find_id', $idOrQueryBuilder);
        } else {
            $queryBuilder = $idOrQueryBuilder;
        }

        $skipSelectFrom = false;
        // in case we define a join, the select from part needs to be added before the join
        // cause otherwise stupid doctrine throws an error. In case the select from is added before
        // it cannot be added twice, cause doctrine throws another supid error
        foreach ($queryBuilder->getDQLPart('from') as $fromPart) {
            /** @var From $fromPart */
            if ($fromPart->getFrom() == $this::BASE_ORM_MODEL) {
                $skipSelectFrom = true;
            }
        }
        if (!$skipSelectFrom) {
            // Apply the select and from clause based on model and alias definitions.
            $queryBuilder->addSelect($baseOrmModelAlias)->from($this::BASE_ORM_MODEL, $baseOrmModelAlias);
        }

        // Apply read rights restrictions.
        static::applyReadRightsQuery($queryBuilder);

        // Handle translations.
        $queryBuilder = static::applyTranslationJoinToQueryBuilder($queryBuilder);

        // --- APPLY SELECT OPTIONS ---
        $baseEntityClass = $this::BASE_ENTITY_CLASS;
        $baseEntityReflection = ReflectionClass::instance($baseEntityClass);
        if ($baseEntityReflection->hasTrait(QueryOptionsTrait::class)) {
            /** @var QueryOptionsTrait $baseEntityClass */
            $defaultQueryOptions = $baseEntityClass::getDefaultQueryOptions();
            if ($defaultQueryOptions && $select = $defaultQueryOptions->getSelect()) {
                $select->applySelectToDoctrineQueryBuilder(
                    queryBuilder: $queryBuilder,
                    baseModelClass: $this::BASE_ORM_MODEL
                );
            }
        }
        // --- END APPLY SELECT OPTIONS ---

        return $queryBuilder;
    }

    /**
     * Shorthand method for creation of a QueryBuilder for internal use
     * @param bool $includeModelSelectFromClause
     * @return DoctrineQueryBuilder
     */
    public static function createQueryBuilder(bool $includeModelSelectFromClause = false): DoctrineQueryBuilder
    {
        $queryBuilder = EntityManagerFactory::createQueryBuilder();
        $baseOrmModelAlias = (static::BASE_ORM_MODEL)::MODEL_ALIAS;
        if ($includeModelSelectFromClause) {
            $queryBuilder->addSelect($baseOrmModelAlias)->from(static::BASE_ORM_MODEL, $baseOrmModelAlias);
        }
        return $queryBuilder;
    }

    /**
     * Applies restrictions to passed QueryBuilder used for loading Entities
     * if Restriction is applied, returns true, else false
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return false;
    }

    /**
     * Applies translation join to query builder to use translations table and load implicit translations
     * @param QueryBuilder $queryBuilder
     * @param null $doctrineModel
     * @return QueryBuilder
     * @throws ReflectionException
     */
    public static function applyTranslationJoinToQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        $doctrineModel = null
    ): DoctrineQueryBuilder {
        // if no attribute is found, the query builder is returned untouched
        if (!($translationAttributeInstance = static::getTranslationAttributeInstance())) {
            return $queryBuilder;
        }
        /** @var DoctrineModel $doctrineModel */
        if (!$doctrineModel) {
            $doctrineModel = static::BASE_ORM_MODEL;
        }
        $tableName = $doctrineModel::getTableName();
        $baseOrmModelAlias = static::getBaseModelAlias();
        $translationAttributeInstance->applyTranslationJoinToQueryBuilder(
            $queryBuilder,
            $tableName,
            $baseOrmModelAlias
        );
        return $queryBuilder;
    }

    /**
     * Returns translation attribute instance if present
     * @return DatabaseTranslation|false
     * @throws ReflectionException
     */
    public static function getTranslationAttributeInstance(): DatabaseTranslation|false
    {
        return DatabaseTranslation::getInstance(static::class);
    }

    /**
     * @return string Returns base model table alias
     */
    public static function getBaseModelAlias(): string
    {
        /** @var DoctrineModel $baseModelClass */
        $baseModelClass = static::BASE_ORM_MODEL;
        return $baseModelClass::MODEL_ALIAS;
    }

    /**
     * This function can be overwritten
     * sometimes processing after mapping is needed, as e.g. we load something from cache, e.g. ProjectSetting
     * and we need to add something there, e.g. Rights DirectorySettings from Account, this should not be cached, as it could be
     * that another account accesses the same project with a different set of rights...
     * @param DefaultObject|null $entity
     * @return DefaultObject|null
     */
    public function postProcessAfterMapping(
        DefaultObject|null &$entity
    ): DefaultObject|null {
        return $entity;
    }

    /**
     * @return DoctrineModel Returns DoctrineModel orm instance
     */
    public function getOrmInstance(): DoctrineModel
    {
        return $this->ormInstance;
    }

    /**
     * This will receive a property where we want to set a value that we are not sure exists, checks the value in the
     * valueClass parameter, and if it exists, it sets it to our property which is passed by reference in the function.
     * @param $property
     * @param $value
     * @param $valueClass
     */
    public function setIfExists(&$property, $value, $valueClass): void
    {
        if (isset($valueClass->$value)) {
            $property = $valueClass->$value;
        }
    }

    /**
     * The main function that should be called from a repository to save everything to the db.
     * This will map the current entity data to the repository data which will then update the db using
     * Kohana ORM. It will also update any entity that is contained in this entity.
     * The depth is used to specify how many levels you want to go down with the update of your entity.
     * E.g. If the depth is 1, the entity will also save the first depth entities inside
     * @param DefaultObject $entity
     * @param int $depth
     * @return Entity|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws ForbiddenException
     * @throws Exception
     * @throws MappingException
     * @throws JsonException
     */
    public function update(
        DefaultObject &$entity,
        int $depth = 1
    ): ?DefaultObject {
        if (!DefaultObject::isEntity($entity)) {
            return $entity;
        }
        /** @var Entity $entity */
        $validationResults = $entity->validate(depth: $depth);
        if ($validationResults !== true) {
            $badRequestException = new BadRequestException('Request contains invalid data');
            $badRequestException->validationErrors = $validationResults;
            throw $badRequestException;
        }
        if (self::$applyRightsRestrictions && !self::canUpdateOrDeleteBasedOnRoles()) {
            return $entity;
        }
        $updatedChildProperties = $this->updateDependentEntities($entity, $depth, false);
        $loadedChildPropertiesAfterUpdate = [];
        // we need the name of the updated column in case of DBENtity
        $changeHistoryAttributeInstance = null;
        if (
            is_a($this, DBEntity::class) && method_exists(
                $entity::class,
                'getChangeHistoryAttribute'
            )
        ) { // this trait is present
            /** @var ChangeHistoryTrait $entityClassName */
            $entityClassName = $entity::class;
            /** @var ChangeHistory $changeHistoryAttributeInstance */
            $changeHistoryAttributeInstance = $entityClassName::getChangeHistoryAttribute();
        }

        // Update the main entity
        if (is_a($entity::class, (string)$this::BASE_ENTITY_CLASS, true)) {
            // in case of an existing enity we first load the current row from the db into an model
            // in order to avoid setting some fields empty
            $translationAttributeInstance = static::getTranslationAttributeInstance();
            $entityManager = EntityManagerFactory::getInstance();
            $reflectionClass = ReflectionClass::instance(static::class);

            $entityId = $entity->id ?? null;
            $hasTranslations = $translationAttributeInstance && $translationAttributeInstance->hasPropertiesToTranslate();
            $translationIsInDefaultLanguage = $translationAttributeInstance && $translationAttributeInstance::isCurrentLanguageCodeDefaultLanguage();

            $modelName = static::BASE_ORM_MODEL;
            if (self::$applyRightsRestrictions) {
                $updateRightsQueryBuilder = static::createQueryBuilder(true);
                $updateRightsQueryApplied = static::applyUpdateRightsQuery($updateRightsQueryBuilder);
                $updateRightsQueryBuilder = $updateRightsQueryApplied ? $updateRightsQueryBuilder : null;
            } else {
                $updateRightsQueryBuilder = null;
            }

            if (!$entityId || ($entityId && (!$hasTranslations || ($hasTranslations && $translationIsInDefaultLanguage)))) {
                // if we have a new entity, we need to persist it anyway
                // if it is not a new entity and the entity has no translation to be cared of (or we are in the default language), we persist it
                // create new entity
                $mapped = $this->mapToRepository($entity);
                if ($mapped) {
                    $updatedID = $entityManager->upsert(
                        $this->ormInstance,
                        $updateRightsQueryBuilder,
                        $changeHistoryAttributeInstance ? $changeHistoryAttributeInstance?->getCreatedColumn() : null,
                        $changeHistoryAttributeInstance ? $changeHistoryAttributeInstance?->getModifiedColumn() : ''
                    );
                    if ($updatedID) {
                        if ($entityId) {
                            // if we are not inserting a new entity, but updating it, we want to return a fully loaded entity back
                            $entityManager->clear();
                            $updatedEntity = $this->find($this->ormInstance->id, false);
                            foreach ($updatedEntity as $propertyName => $value) {
                                if (!isset($updatedChildProperties[$propertyName])) {
                                    // we put all properties that are not updated child properties from updatedEntity to entity
                                    $entity->$propertyName = $value;
                                    if ($value instanceof DefaultObject) {
                                        $loadedChildPropertiesAfterUpdate[$propertyName] = $propertyName;
                                        if ($value->getParent() === $updatedEntity) {
                                            // As $entity is used further it is critical to either add the $value as child
                                            // and as well implicitely set $entity as parent of $value
                                            // otherwise the parent or $value will remain $updatedEntity and stay in Nirvana
                                            $entity->addChildren($value);
                                        }
                                    }
                                }
                            }
                        } else {
                            $entity->id = $updatedID;
                        }
                        if ($hasTranslations) {
                            $translationAttributeInstance->updateOrCreateTranslation($entity, $this);
                        }
                    }
                }
            } elseif ($entityId && $hasTranslations) {
                if ($changeHistoryAttributeInstance) {
                    // in this case we need to upate the created and updated time and persist the main entity anyway, indifferent from translation

                    // we load current data and update created and updated columns
                    $this->ormInstance = isset($this->ormInstance) && $this->ormInstance ? $this->ormInstance : new (static::BASE_ORM_MODEL)();
                    $this->ormInstance->id = $entityId;
                    $this->mapCreatedAndUpdatedTime($entity);
                    $entityManager->upsert(
                        $this->ormInstance,
                        $updateRightsQueryBuilder,
                        $changeHistoryAttributeInstance ? $changeHistoryAttributeInstance?->getCreatedColumn() : null,
                        $changeHistoryAttributeInstance ? $changeHistoryAttributeInstance?->getModifiedColumn() : ''
                    );
                }
                $translationAttributeInstance->updateOrCreateTranslation($entity, $this);
            }
        }
        $this->updateDependentEntities(
            $entity,
            $depth,
            true,
            $updatedChildProperties,
            $loadedChildPropertiesAfterUpdate
        );
        return $entity;
    }

    /**
     * Based on RolesRequiredForUpdate returns wheather current Account has the right to update or delete current Entity based on his Roles
     * @return bool
     * @throws ReflectionException
     */
    public static function canUpdateOrDeleteBasedOnRoles(): bool
    {
        $rolesRequiredForUpdate = [];
        if (isset(StaticRegistry::$rolesRequiredForUpdateOnEntities[static::BASE_ENTITY_CLASS])) {
            $rolesRequiredForUpdate = StaticRegistry::$rolesRequiredForUpdateOnEntities[static::BASE_ENTITY_CLASS];
        } else {
            $entityReflectionClass = ReflectionClass::instance(static::BASE_ENTITY_CLASS);
            /** @var RolesRequiredForUpdate $rolesRequiredForUpdateAttribute */
            $rolesRequiredForUpdateAttribute = $entityReflectionClass->getAttributeInstance(
                RolesRequiredForUpdate::class
            );
            if ($rolesRequiredForUpdateAttribute) {
                $rolesRequiredForUpdate = $rolesRequiredForUpdateAttribute->rolesRequiredForUpdate;
            } else {
                $rolesRequiredForUpdate = [];
            }
            StaticRegistry::$rolesRequiredForUpdateOnEntities[static::BASE_ENTITY_CLASS] = $rolesRequiredForUpdate;
        }
        if ($rolesRequiredForUpdate) {
            if (!AuthService::instance()->getAccount()) {
                return false;
            }
            if (!AuthService::instance()->getAccount()->roles->hasRoles(...$rolesRequiredForUpdate)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Updates dependent Entities, takes care to not update $properties, that depend on the main Entity to be persisted already
     * Considers alreaadyUpdatedChildProperties and skips them, returns all properties that have been updated
     * @param DefaultObject $entity
     * @param int $depth
     * @param bool $entityAlreadyStored
     * @param array $alreaadyUpdatedChildProperties
     * @param array $loadedChildPropertiesAfterUpdate
     * @return array
     * @throws ReflectionException
     */
    public function updateDependentEntities(
        DefaultObject &$entity,
        int $depth,
        bool $entityAlreadyStored,
        array $alreaadyUpdatedChildProperties = [],
        array $loadedChildPropertiesAfterUpdate = []
    ): array {
        $updatedChildProperties = [];
        // Update the objects in the main entity, only for one level
        if ($depth > 0) {
            foreach ($entity as $propertyName => $value) {
                if (!$value || !(is_object($value) && (DefaultObject::isEntity($value) || $value instanceof EntitySet))) {
                    continue;
                }
                if (isset($alreaadyUpdatedChildProperties[$propertyName])) {
                    continue;
                }
                // these are properties hat have been loaded after the update operation through find, if some of them are entities loaded implicitly,
                // it makes no sense to update these recursively
                if (isset($loadedChildPropertiesAfterUpdate[$propertyName])) {
                    continue;
                }
                $propertyIsParent = false;
                /** @var LazyLoad $lazyloadAttribute */
                $lazyloadAttribute = ReflectionClass::instance($entity::class)->getProperty(
                    $propertyName
                )->getAttributeInstance(LazyLoad::class);
                $propertyIsParent = $propertyIsParent || ($lazyloadAttribute && $lazyloadAttribute?->addAsParent ?? false);
                // We do not update parent Entities
                if ($propertyIsParent) {
                    continue;
                }
                $propertyReflectionClass = ReflectionClass::instance($value::class);
                /** @var ReflectionAttribute[] $lazyloadRepoAttributes */
                $lazyloadRepoAttributes = $propertyReflectionClass->getAttributes(LazyLoadRepo::class, \ReflectionAttribute::IS_INSTANCEOF);
                $propertyHasDBRepo = false;
                foreach ($lazyloadRepoAttributes as $lazyloadRepoAttribute) {
                    /** @var LazyLoadRepo $attributeInstance */
                    $attributeInstance = $lazyloadRepoAttribute->newInstance();
                    if (
                        in_array(
                            $attributeInstance->repoType,
                            LazyLoadRepo::DATABASE_REPOS
                        )
                    ) {
                        $propertyHasDBRepo = true;
                        break;
                    }
                }
                if (!$propertyHasDBRepo) {
                    continue;
                }
                // We check if property has Database
                // we cannot store an entity that depends on the current entity which is not yet stored
                if ($value::dependsOn($entity)) {
                    if (!$entityAlreadyStored) {
                        continue;
                    } // we need to set the entityId in the dependent classes
                    else {
                        $propertyContainingEntityId = $value::getPropertyContainingIdForParentEntity($entity);
                        if ($propertyContainingEntityId) {
                            if (DefaultObject::isEntity($value)) {
                                $value->$propertyContainingEntityId = $entity->id;
                            } elseif ($value instanceof EntitySet) {
                                foreach ($value->getElements() as $subEntity) {
                                    $subEntity->$propertyContainingEntityId = $entity->id;
                                }
                            }
                        } else {
                            // it does not make sense to persist a dependent entity where we cannot store the parentId
                            continue;
                        }
                    }
                }
                $databaseRepoCLasses = $value::getDatabaseRelatedRepoClasses();
                foreach ($databaseRepoCLasses as $repoClass) {
                    $repoInstance = $repoClass && class_exists($repoClass) ? new $repoClass() : null;
                    $hasNoRecursiveUpdateAttribute = $propertyReflectionClass->hasAttribute(
                        NoRecursiveUpdate::class,
                        \ReflectionAttribute::IS_INSTANCEOF
                    );

                    if ($repoInstance && !$hasNoRecursiveUpdateAttribute) {
                        if (method_exists($repoInstance, 'update')) {
                            $updatedChildProperties[$propertyName] = true;
                            $updatedChild = $repoInstance->update($value, --$depth);
                            $entity->$propertyName = $updatedChild;
                            if ($updatedChild instanceof EntitySet) {
                                $updatedChild->regenerateElementsByUniqueKey();
                            } elseif (
                                DefaultObject::isEntity($updatedChild) && property_exists(
                                    $entity,
                                    $propertyName . 'Id'
                                ) && isset($updatedChild->id)
                            ) {
                                $entity->{$propertyName . 'Id'} = $updatedChild->id;
                            }
                        }
                    }
                }
            }
        }
        return $updatedChildProperties;
    }

    /**
     * Applies restrictions to passed QueryBuilder used for updating Entities,
     * if Restriction is applied, returns true, else false
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }

    /**
     * @param Entity $entity
     * @return true
     * @throws BadRequestException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function delete(DefaultObject &$entity): bool
    {
        if (is_a($entity, $this::BASE_ENTITY_CLASS ?? '') && $entity->id) {
            $baseOrmModelAlias = (static::BASE_ORM_MODEL)::MODEL_ALIAS;
            // we apply delete rights on query
            if (self::$applyRightsRestrictions) {
                if (!self::canUpdateOrDeleteBasedOnRoles()) {
                    return false;
                }
                $deleteRightsQueryBuilder = static::createQueryBuilder(true);
                if (static::applyDeleteRightsQuery($deleteRightsQueryBuilder)) {
                    $queryBuilder = $deleteRightsQueryBuilder;
                }
            }

            if (!isset($queryBuilder)) {
                $queryBuilder = self::createQueryBuilder(true);
            }
            $queryBuilder->andWhere($baseOrmModelAlias . '.id = :find_id')->setParameter('find_id', $entity->id);

            $instance = $queryBuilder->getQuery()->getOneOrNullResult();
            if ($instance) {
                try {
                    // handle translation update
                    $translationAttributeInstance = static::getTranslationAttributeInstance();
                    if ($translationAttributeInstance && !empty($translationAttributeInstance->propertiesToTranslate)) {
                        $deleteTranslationResult = $translationAttributeInstance->deleteTranslation($entity, $this);
                    }

                    $entityManager = EntityManagerFactory::getInstance();
                    $entityManager->remove($instance);
                    $entityManager->flush();

                    // Invalidate entity registry cache using the same QueryBuilder structure as find()
                    $cacheQueryBuilder = $this->buildFindQueryBuilder($entity->id);
                    DoctrineEntityRegistry::getInstance()->remove(static::class, $cacheQueryBuilder);
                } catch (Throwable $t) {
                    throw new BadRequestException('Error deleting ' . $this::BASE_ORM_MODEL . ': ' . $t);
                }
            }
            return true;
        }

        throw new BadRequestException('Error deleting ' . $this::BASE_ORM_MODEL . ': Object not found');
    }

    /**
     * Applies restrictions to passed QueryBuilder used for deleting Entities
     * if Restriction is applied, returns true, else false
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyUpdateRightsQuery($queryBuilder);
    }
}
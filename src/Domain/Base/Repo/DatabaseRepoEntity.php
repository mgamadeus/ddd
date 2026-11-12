<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo;

use DDD\Domain\Common\Services\EntityModelGeneratorService;
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
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
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
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ReflectionAttribute;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
    public const string|null BASE_ENTITY_CLASS = null;

    /** @var string */
    public const string|null BASE_ORM_MODEL = null;

    /** @var int The default decursive depth for update operations */
    public const int UPDATE_DEFAULT_RECURSIVE_DEPTH = 2;

    /** @var bool[] Whether the table of a repository class is excluded by the application, by repository class */
    protected static array $isTableExcludedByRepoClass = [];

    /**
     * @var string The application config key that switches back to mapping every relation Doctrine holds as loaded,
     * also those an earlier query of the request left in the unit of work
     */
    public const string MAP_RELATIONS_LOADED_BY_EARLIER_QUERIES_CONFIG_KEY = 'Base.Repo.database.mapRelationsLoadedByEarlierQueries';

    /**
     * @var string[]|null The relations the query of the load being mapped fetch-joins, as dotted paths of model
     * properties from the root (`customer`, `customer.address`). Null outside a load: nothing is known about the
     * query a passed model came from, and every loaded relation is mapped.
     */
    public static ?array $fetchJoinedRelationPathsOfCurrentLoad = null;

    /** @var string The path of the relation whose model is being mapped; empty on the root of the load */
    public static string $relationPathOfCurrentMapping = '';

    /**
     * @var bool|null {@see MAP_RELATIONS_LOADED_BY_EARLIER_QUERIES_CONFIG_KEY}; null = read from the config on first
     * use
     */
    public static ?bool $mapRelationsLoadedByEarlierQueries = null;

    /**
     * Whether a relation of the model being mapped becomes a property of the entity: Doctrine holds it as loaded AND
     * the query of this load fetch-joined it (an expand, or a join with `addSelect()` of a service's own query).
     *
     * Doctrine also reports a relation as loaded when an earlier query of the request left its target in the unit of
     * work. Mapping that would give an entity a relation nobody asked for — depending on what ran before, and without
     * the read rights of the target being applied to this load. Such a relation stays unset and lazy-loads on access.
     *
     * @param string $propertyName The relation property of the model
     * @return bool
     */
    protected function isRelationFetchedByCurrentLoad(string $propertyName): bool
    {
        if (!$this->ormInstance->isLoaded($propertyName)) {
            return false;
        }
        if (self::$fetchJoinedRelationPathsOfCurrentLoad === null) {
            return true;
        }
        if (self::$mapRelationsLoadedByEarlierQueries === null) {
            self::$mapRelationsLoadedByEarlierQueries = (bool)Config::get(self::MAP_RELATIONS_LOADED_BY_EARLIER_QUERIES_CONFIG_KEY);
        }
        if (self::$mapRelationsLoadedByEarlierQueries) {
            return true;
        }
        return in_array(
            static::getRelationPathInCurrentMapping($propertyName),
            self::$fetchJoinedRelationPathsOfCurrentLoad,
            true
        );
    }

    /**
     * The path of a relation of the model being mapped, from the root of the load
     *
     * @param string $propertyName
     * @return string
     */
    protected static function getRelationPathInCurrentMapping(string $propertyName): string
    {
        return self::$relationPathOfCurrentMapping === ''
            ? $propertyName
            : self::$relationPathOfCurrentMapping . '.' . $propertyName;
    }

    /**
     * Refuses a write through this repository when the application leaves its table out of the schema
     * (`Base.Repo.database.excludedTables`, {@see EntityModelGeneratorService::getExcludedSqlTableNames()}) — instead
     * of the database's "table doesn't exist". Checked once per repository class and process.
     *
     * @throws InternalErrorException
     */
    public static function assertTableIsWritable(): void
    {
        if (!isset(self::$isTableExcludedByRepoClass[static::class])) {
            $tableName = static::BASE_ORM_MODEL ? (static::BASE_ORM_MODEL)::getTableName() : null;
            self::$isTableExcludedByRepoClass[static::class] = $tableName !== null
                && EntityModelGeneratorService::isExcludedSqlTableName($tableName);
        }
        if (self::$isTableExcludedByRepoClass[static::class]) {
            throw new InternalErrorException(
                'table ' . (static::BASE_ORM_MODEL)::getTableName() . ' is listed in '
                . EntityModelGeneratorService::EXCLUDED_TABLES_CONFIG_KEY . ' of this app; ' . static::class
                . ' cannot write it (remove the entry and create the table to use it)'
            );
        }
    }

    /** Forgets the cached exclusion checks (tests, a changed configuration) */
    public static function forgetTableExclusionChecks(): void
    {
        self::$isTableExcludedByRepoClass = [];
    }

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

        // The select of this find decides which properties of the entity it loads are hidden; the hides an
        // enclosing set load collected stay untouched
        $propertiesToHideOfEnclosingLoad = SelectOptions::$propertiesToHideByJoinPath;
        SelectOptions::$propertiesToHideByJoinPath = [];
        try {
            $queryBuilder = $this->buildFindQueryBuilder($idOrQueryBuilder);
            $propertiesToHideBasedOnSelectOptions = $loadedOrmInstance ? [] : SelectOptions::$propertiesToHideByJoinPath;
        } finally {
            SelectOptions::$propertiesToHideByJoinPath = $propertiesToHideOfEnclosingLoad;
        }

        if ($useEntityRegistrCache) {
            // Check if an element exists in the registry.
            $entityInstance = $entityRegistry->get(static::class, $queryBuilder);
            if ($entityInstance) {
                if ($propertiesToHideBasedOnSelectOptions) {
                    $entityInstance->addPropertiesToHideRecursively($propertiesToHideBasedOnSelectOptions);
                }
                // If we have a loaded Orm Instance, it is possible that it has expanded options that are different to the ones of the cached entity
                // Therefor we have to run mapToEntity again as otherwise we could end up with an enity that does not contain the expanded properties
                if ($loadedOrmInstance){
                    $this->ormInstance = $loadedOrmInstance;
                    $entityInstance = $this->mapToEntity($useEntityRegistrCache, $initiatorClasses);
                }
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
            $ormInstance = $ormInstance[0] ?? null;
            // The unit of work hands back an already managed model as it is — after a select-restricted load of the
            // same row in this request, with the unselected columns missing. Re-read it whole.
            if ($ormInstance && $this->isPartiallyLoadedOrmInstance($ormInstance)) {
                $refreshQuery = $queryBuilder->getQuery();
                $refreshQuery->disableResultCache()->useQueryCache(false)->setHint(Query::HINT_REFRESH, true);
                $ormInstance = $refreshQuery->setMaxResults(1)->getResult()[0] ?? null;
            }
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
        if ($loadedOrmInstance) {
            // a model of an enclosing load: the relations that load fetch-joined decide
            $entityInstance = $this->mapToEntity($useEntityRegistrCache, $initiatorClasses);
        } else {
            // this find ran its own query: its fetch joins decide which relations are mapped
            $fetchJoinedRelationPathsOfEnclosingLoad = self::$fetchJoinedRelationPathsOfCurrentLoad;
            $relationPathOfEnclosingMapping = self::$relationPathOfCurrentMapping;
            self::$fetchJoinedRelationPathsOfCurrentLoad = $queryBuilder->getFetchJoinedRelationPaths();
            self::$relationPathOfCurrentMapping = '';
            try {
                $entityInstance = $this->mapToEntity($useEntityRegistrCache, $initiatorClasses);
            } finally {
                self::$fetchJoinedRelationPathsOfCurrentLoad = $fetchJoinedRelationPathsOfEnclosingLoad;
                self::$relationPathOfCurrentMapping = $relationPathOfEnclosingMapping;
            }
        }
        // A find with a select shows what a list with the same select shows: the properties that were not selected
        // are hidden, they do not appear with their class defaults
        if ($propertiesToHideBasedOnSelectOptions && $entityInstance) {
            $entityInstance->addPropertiesToHideRecursively($propertiesToHideBasedOnSelectOptions);
        }
        // The registry key is the plain find of this id. A model a caller loaded with a select (a list with
        // `select=…`, an expand with `select=…`, a partial DQL) lacks the unselected columns: registered under that key,
        // it would be served to every later find of the id — and with the extended registry cache to other requests.
        if (!$loadedOrmInstance || !$this->isPartiallyLoadedOrmInstance($loadedOrmInstance)) {
            $entityRegistry->add($entityInstance, static::class, $queryBuilder, $deferredCaching);
        }
        // Entity Manager's unit of work cache of various types especially loaded DoctrineModels can end up using
        // the whole allocated memory, so if the memory usage is high, we clear it
        if (DDDService::instance()->isMemoryUsageHigh()) {
            EntityManagerFactory::clearAllInstanceCaches();
        }
        // post processing needs to happen after storage!!!
        return $this->postProcessAfterMapping($entityInstance);
    }

    /**
     * Whether Doctrine hydrated only some of the model's mapped columns (a select-restricted load): its original entity
     * data — the columns the hydration actually read — lacks a mapped field. A model the entity manager does not manage
     * counts as partial, so it is never registered on a guess.
     */
    protected function isPartiallyLoadedOrmInstance(DoctrineModel $ormInstance): bool
    {
        $entityManager = EntityManagerFactory::getInstance();
        $originalEntityData = $entityManager->getUnitOfWork()->getOriginalEntityData($ormInstance);
        if ($originalEntityData === []) {
            return true;
        }
        foreach ($entityManager->getClassMetadata($ormInstance::class)->getFieldNames() as $fieldName) {
            if (!array_key_exists($fieldName, $originalEntityData)) {
                return true;
            }
        }
        return false;
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
                    baseModelClass: $this::BASE_ORM_MODEL,
                    expandOptions: $defaultQueryOptions->getExpandOptions(),
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
        int $depth = self::UPDATE_DEFAULT_RECURSIVE_DEPTH
    ): ?DefaultObject {
        return $this->persistEntity($entity, $depth, false);
    }

    /**
     * Strict insert of the entity as a NEW row — a plain `INSERT` without `ON DUPLICATE KEY UPDATE` (PostgreSQL: without
     * `ON CONFLICT`), never an update, also when the entity carries an id (that id is inserted). Use it for rows whose
     * identity is a unique business key where a collision must be detected instead of merged, e.g. an append-only log
     * with a sequence number: the database refuses the duplicate, the caller retries. {@see update()} stays the default
     * upsert.
     *
     * Same validation, role check, column mapping, read-after-write and translations as {@see update()}; the row is
     * gated by the CREATE rights ({@see applyCreateRightsQuery()}). Dependent entities are written up to $depth as in
     * {@see update()} — they are upserted, only this entity is strictly inserted.
     *
     * @param DefaultObject $entity
     * @param int $depth
     * @return Entity|null
     * @throws UniqueConstraintViolationException A unique key or the primary key already exists — unchanged, nothing written
     * @throws DeadlockException A deadlock / Galera certification conflict — unchanged, the caller decides on a retry
     * @throws LockWaitTimeoutException A lock wait timeout — unchanged
     * @throws ForbiddenException The inserted row is not visible under the create rights (the insert is reverted)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws Exception
     * @throws MappingException
     * @throws JsonException
     */
    public function insert(
        DefaultObject &$entity,
        int $depth = self::UPDATE_DEFAULT_RECURSIVE_DEPTH
    ): ?DefaultObject {
        return $this->persistEntity($entity, $depth, true);
    }

    /**
     * The write behind {@see update()} (upsert) and {@see insert()} ($insertOnly: strict INSERT under the create rights).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws ForbiddenException
     * @throws Exception
     * @throws MappingException
     * @throws JsonException
     */
    /**
     * An object of a parent class of the repository's entity class (typically a module class whose application
     * subclass took over the repository) is never written: the write below only maps instances of BASE_ENTITY_CLASS.
     * That used to be a silent no-op (no row, no error); it is refused loudly instead.
     *
     * @param DefaultObject $entity
     * @return void
     * @throws InternalErrorException When the entity's class is a parent class of BASE_ENTITY_CLASS
     */
    protected static function assertEntityIsNotAParentClassOfTheRepoEntity(DefaultObject $entity): void
    {
        $repoEntityClass = (string)static::BASE_ENTITY_CLASS;
        if ($repoEntityClass === '' || $entity instanceof $repoEntityClass || !is_subclass_of($repoEntityClass, $entity::class)) {
            return;
        }
        throw new InternalErrorException(
            'Cannot persist an instance of ' . $entity::class . ' through ' . static::class . ', whose entity class is its subclass '
            . $repoEntityClass . '. Create the object with ' . $entity::class . '::newInstance() (it builds the class the '
            . 'application registered) instead of new.'
        );
    }

    protected function persistEntity(DefaultObject &$entity, int $depth, bool $insertOnly): ?DefaultObject
    {
        if (!DefaultObject::isEntity($entity)) {
            return $entity;
        }
        static::assertEntityIsNotAParentClassOfTheRepoEntity($entity);
        static::assertTableIsWritable();
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

            $entityId = $entity->id ?? null;
            $hasTranslations = $translationAttributeInstance && $translationAttributeInstance->hasPropertiesToTranslate();
            $translationIsInDefaultLanguage = $translationAttributeInstance && $translationAttributeInstance::isCurrentLanguageCodeDefaultLanguage();

            if (self::$applyRightsRestrictions) {
                $updateRightsQueryBuilder = static::createQueryBuilder(true);
                // INSERT (new entity, no id) is gated by CREATE rights; UPDATE (existing id) by UPDATE rights.
                // upsert() re-checks THIS same QB against the row after insert, so the create/update split must be
                // decided here. applyCreateRightsQuery defaults to applyUpdateRightsQuery (no behaviour change unless
                // an entity overrides it to allow create-but-not-update, e.g. shared lookup domains).
                $rightsQueryApplied = $entityId && !$insertOnly
                    ? static::applyUpdateRightsQuery($updateRightsQueryBuilder)
                    : static::applyCreateRightsQuery($updateRightsQueryBuilder);
                $updateRightsQueryBuilder = $rightsQueryApplied ? $updateRightsQueryBuilder : null;
            } else {
                $updateRightsQueryBuilder = null;
            }

            if ($insertOnly || !$entityId || ($entityId && (!$hasTranslations || ($hasTranslations && $translationIsInDefaultLanguage)))) {
                // if we have a new entity, we need to persist it anyway
                // if it is not a new entity and the entity has no translation to be cared of (or we are in the default language), we persist it
                // create new entity
                $mapped = $this->mapToRepository($entity);
                if ($mapped) {
                    // DB_USE_READ_AFTER_WRITE controls whether the entity is re-read from DB after upsert
                    // to capture DB-generated values (generated columns, JSON_MERGE_PATCH results, timestamps).
                    // When enabled, the upsert + reload are wrapped in a transaction to guarantee
                    // read-after-write consistency in ProxySQL + Galera setups with connection multiplexing,
                    // where the SELECT could otherwise be routed to a stale replica.
                    $useReadAfterWrite = self::extractBool(Config::getEnv('DB_USE_READ_AFTER_WRITE') ?? false);
                    $connection = $entityManager->getConnection();
                    $startedTransaction = false;
                    if ($useReadAfterWrite && $entityId && !$connection->isTransactionActive()) {
                        $connection->beginTransaction();
                        $startedTransaction = true;
                    }
                    try {
                        // NOTE: upsert() derives the created/modified column names itself (ChangeHistory defaults); the
                        // two trailing args this used to pass were silently ignored (PHP drops extra positional args)
                        // and collided with the new $onlyFieldNames param — dropped. Full upsert ⇒ $onlyFieldNames null.
                        $updatedID = $insertOnly
                            ? $entityManager->insert($this->ormInstance, $updateRightsQueryBuilder)
                            : $entityManager->upsert($this->ormInstance, $updateRightsQueryBuilder);
                    } catch (Throwable $t) {
                        if ($startedTransaction) {
                            try { $connection->rollBack(); } catch (Throwable) {}
                        }
                        throw $t;
                    }
                    if ($updatedID) {
                        if ($entityId && $useReadAfterWrite) {
                            // Re-read entity from DB to capture generated columns, JSON merges, timestamps etc.
                            $entityManager->clear();
                            try {
                                $updatedEntity = $this->find($this->ormInstance->id, false);
                            } finally {
                                if ($startedTransaction) {
                                    try {
                                        $connection->commit();
                                    } catch (Throwable $commitFailure) {
                                        // A failed COMMIT loses the whole upsert+reload transaction server-side, and
                                        // DBAL has already zeroed its nesting counter (finally in Connection::commit) —
                                        // a rollBack() here could never reach the driver again (the old fallback was a
                                        // guaranteed no-op that left an orphaned server-side transaction poisoning the
                                        // long-lived worker connection). close() clears the orphan (auto-reconnect on
                                        // next use), and the failure is RETHROWN: a lost write must not look like a
                                        // successful update. (If the reload find() above also threw, this commit
                                        // failure replaces it — both surface the same broken write.)
                                        $connection->close();
                                        throw $commitFailure;
                                    }
                                }
                            }
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
                        } elseif (!$entityId) {
                            $entity->id = $updatedID;
                        }
                        if ($changeHistoryAttributeInstance && !($entityId && $useReadAfterWrite) && $this instanceof DBEntity) {
                            // the response shows the persisted times, not the payload's (a re-read already has them)
                            $this->applyPersistedChangeHistory($entity, (bool)$entityId);
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
                    // Same 2-arg form as the main call site above: upsert() derives the created/modified columns
                    // itself, and the old 3rd/4th args here flowed a ?string into the ?array $onlyFieldNames
                    // parameter — a hard TypeError under strict_types whenever this translations+change-history
                    // path ran with a non-null created column.
                    $entityManager->upsert(
                        $this->ormInstance,
                        $updateRightsQueryBuilder
                    );
                    if ($this instanceof DBEntity) {
                        $this->applyPersistedChangeHistory($entity, true);
                    }
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
                            // instead of using the repo update itself, we persist the Entity by calling it's udpate method
                            // since otherwise we miss to run custom code that has to be executed on update
                            // pass $depth - 1 (NOT the old post-decrement $depth--). $depth-- starved later
                            // SIBLINGS — the first child got the full depth, each subsequent child one less, purely by
                            // foreach order — making recursion depth depend on property declaration order. $depth - 1
                            // decrements uniformly per level and mutates nothing. See upstream-handoff doc.
                            $updatedChild = $value->update($depth - 1);
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
     * Applies restrictions to the QueryBuilder used for the INSERT (create) branch of update()/upsert().
     * Defaults to applyUpdateRightsQuery, so for every existing entity create == update rights (no behaviour
     * change). Override to allow CREATE while DENYING UPDATE — e.g. a shared global lookup entity (Domain/Website)
     * that any account may create-or-find but none may mutate. Without this split the upsert's post-insert rights
     * re-check (DoctrineEntityManager::upsert) would roll back every insert whenever update rights are denied,
     * because the freshly inserted row carries no account linkage to satisfy an update-rights WHERE.
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyUpdateRightsQuery($queryBuilder);
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
        static::assertTableIsWritable();
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
                        $translationAttributeInstance->deleteTranslation($entity, $this);
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
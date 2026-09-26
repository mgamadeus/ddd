<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\Traits\EntityTrait;
use DDD\Domain\Base\Entities\Traits\ValueObjectTrait;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Encryption\EncryptionScopes;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

class DBEntity extends DatabaseRepoEntity
{
    /**
     * @var array Used to avoid recursion on mapToEntity and mapPropertyToEntity
     * e.g. Post > PostMediaItems > Post
     * It stores in an associative array or already mapped Entities by the spl_object_id
     * that was the basis for the mapping to the Entity
     * <objectIdOfOrmInstance> => mapped Entity
     */
    protected static $ormInstanceToEntityAllocation = [];

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
        if ($propertyContainingId) {
            $selfID = $initiatingEntity->$propertyContainingId;
            if (!$selfID) {
                return null;
            }
            return $this->find($selfID, $lazyloadAttributeInstance->useCache);
        } else {
            $queryBuilder = DBEntitySet::getQueryBuilderForLazyload(static::class, $initiatingEntity, $lazyloadAttributeInstance);
            if (!$queryBuilder) {
                return null;
            }
            return $this->find($queryBuilder, $lazyloadAttributeInstance->useCache);
        }
    }

    /**
     * @param array $initiatorClasses
     * @return Entity|EntitySet|null
     * @throws ReflectionException
     */
    public function mapToEntity(
        bool $useEntityRegistryCache = true,
        array $initiatorClasses = []
    ): ?DefaultObject {
        // A stored date-time carries no offset, so hydrating it while an input zone is open would re-read it as
        // wall-clock time in THAT zone: the instant shifts by the offset, and a value inside a spring-forward gap
        // throws out of hydration. The zone belongs around model-supplied arguments only, never around a repository
        // read — fail loudly instead of returning a silently shifted entity.
        if (SerializerRegistry::$inputTimezone !== null) {
            throw new InternalErrorException(
                'Entity hydration while SerializerRegistry::$inputTimezone is set (' .
                SerializerRegistry::$inputTimezone->getName() .
                '): stored date-times would be re-read as local wall-clock time. Load entities outside the ' .
                'withInputTimezone() region.'
            );
        }
        // on the highest level, we first clear ormInstanceToEntityAllocation
        if (empty($initiatorClasses)) {
            self::$ormInstanceToEntityAllocation = [];
        }

        /** @var ChangeHistoryTrait $entityClass */
        $entityClass = $this->ormInstance::ENTITY_CLASS;
        /** @var EntityTrait $entityInstance */
        $entityInstance = new $entityClass();
        $entityReflectionClass = ReflectionClass::instance((string)$entityClass);

        /** @var ChangeHistory $changeHistoryAttributeInstance */
        if (method_exists($entityClass, 'getChangeHistoryAttribute')) {
            $changeHistoryAttributeInstance = $entityClass::getChangeHistoryAttribute(true);
            $createdColumn = $changeHistoryAttributeInstance->getCreatedColumn();
            $modifiedColumn = $changeHistoryAttributeInstance->getModifiedColumn();
            $createdTime = null;
            if (isset($this->ormInstance->$createdColumn) && $this->ormInstance->$createdColumn) {
                if ($changeHistoryAttributeInstance->getCreatedColumnStyle() == ChangeHistory::TIMESTAMP) {
                    $createdTime = DateTime::fromTimestamp($this->ormInstance->$createdColumn);
                } elseif ($changeHistoryAttributeInstance->getCreatedColumnStyle() == ChangeHistory::DATETIME_ATOM) {
                    $createdTime = DateTime::fromString($this->ormInstance->$createdColumn);
                } elseif ($changeHistoryAttributeInstance->getCreatedColumnStyle() == ChangeHistory::DATETIME_SIMPLE) {
                    $createdTime = DateTime::fromTimestamp(
                        $this->ormInstance->$createdColumn->getTimestamp(),
                        DateTime::SIMPLE
                    );
                }
            }
            $modifiedTime = null;
            if (isset($this->ormInstance->$modifiedColumn) && $this->ormInstance->$modifiedColumn) {
                if ($changeHistoryAttributeInstance->getModifiedColumnStyle() == ChangeHistory::TIMESTAMP) {
                    $modifiedTime = DateTime::fromTimestamp($this->ormInstance->$modifiedColumn);
                } elseif ($changeHistoryAttributeInstance->getModifiedColumnStyle() == ChangeHistory::DATETIME_ATOM) {
                    $modifiedTime = DateTime::fromString($this->ormInstance->$modifiedColumn);
                } elseif ($changeHistoryAttributeInstance->getModifiedColumnStyle() == ChangeHistory::DATETIME_SIMPLE) {
                    $modifiedTime = DateTime::fromTimestamp(
                        $this->ormInstance->$modifiedColumn->getTimestamp(),
                        DateTime::SIMPLE
                    );
                }
            }
            /** @var ChangeHistoryTrait $entityInstance */
            $entityInstance->changeHistory = $changeHistoryAttributeInstance->clone();
            if ($createdTime) {
                $entityInstance->changeHistory->createdTime = $createdTime;
            }
            if ($modifiedTime) {
                $entityInstance->changeHistory->modifiedTime = $modifiedTime;
            }
        }

        // apply translation content if applicable
        if ($translationAttributeInstance = static::getTranslationAttributeInstance()) {
            $translationAttributeInstance->applyTranslationToDoctrineModelInstance($this->ormInstance);
        }
        // we set the ormInstanceToEntityAllocation
        self::$ormInstanceToEntityAllocation[spl_object_id($this->ormInstance)] = $entityInstance;

        // map all fields from ormInstance to Entity
        /** @var DefaultObject $entityInstance */
        foreach ($this->ormInstance as $propertyName => $propertyValue) {
            $this->mapPropertyToEntity($entityInstance, $propertyName, $initiatorClasses, $useEntityRegistryCache);
        }
        return $entityInstance;
    }

    /**
     * Maps single property from repository to Entity
     * @param DefaultObject $entity
     * @param string $propertyName
     * @param array $initiatorClasses
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function mapPropertyToEntity(
        DefaultObject &$entity,
        string $propertyName,
        array $initiatorClasses = [],
        bool $useEntityRegistryCache = true
    ) {
        if (!isset($this->ormInstance->$propertyName)) {
            return;
        }
        $entityPropertyName = $propertyName;

        $entityReflectionClass = ReflectionClass::instance($entity::class);

        // If property is virtual column, and no regular column property exists, we use regular name
        // Examples:
        // #[DatabaseVirtualColumn(as: '(IFNULL(battleTeamId, 0))')]
        // public ?int $battleTeamId; => virtualBattleTeamId will not overwrite battleTeamId
        //
        // #[DatabaseColumn(ignoreProperty: true)]
        // #[DatabaseVirtualColumn(as: "(CAST(JSON_UNQUOTE(JSON_EXTRACT(mediaItemContent, '$.height')) AS SIGNED))")]
        // public ?int $height; => height will be set based on virtualHeight
        if (isset($this->ormInstance::$virtualColumns[$propertyName]['referenceColumnStored']) && !$this->ormInstance::$virtualColumns[$propertyName]['referenceColumnStored']) {
            $entityPropertyName = DatabaseVirtualColumn::getColumnNameForVirtualColumn($propertyName);
        }
        if (!$entityReflectionClass->hasProperty($entityPropertyName)) {
            return;
        }

        // First we check if the Entity is already loaded, this is done to safe resources
        if (
            is_object($this->ormInstance->$propertyName) && isset(
                self::$ormInstanceToEntityAllocation[spl_object_id(
                    $this->ormInstance->$propertyName
                )]
            )
        ) {
            // there is already an Entity that has been mapped from the same ORM instance
            $propertyEntity = self::$ormInstanceToEntityAllocation[spl_object_id($this->ormInstance->$propertyName)];
            $entity->$entityPropertyName = $propertyEntity;
            $addAsParent = $entityReflectionClass->isLazyLoadedPropertyToBeAddedAsParent($entityPropertyName);
            if ($addAsParent) {
                $entity->setParent($propertyEntity);
                $propertyEntity->addChildren($entity);
            } else {
                $entity->addChildren($propertyEntity);
                $propertyEntity->setParent($entity);
            }
            return;
        }

        $entityReflectionProperty = $entityReflectionClass->getProperty($entityPropertyName);

        $ormModelReflectionClass = ReflectionClass::instance($this->ormInstance::class);
        $ormModelReflectionProperty = $ormModelReflectionClass->getProperty($propertyName);

        /** @var ReflectionNamedType $possibleEntityTypes */
        $possibleEntityTypes = [];
        if ($entityReflectionProperty->getType() instanceof ReflectionUnionType) {
            foreach ($entityReflectionProperty->getType()->getTypes() as $type) {
                if ($type->getName() != 'null') {
                    $possibleEntityTypes[] = $type;
                }
            }
        } elseif ($entityReflectionProperty->getType() instanceof ReflectionNamedType) {
            $possibleEntityTypes[] = $entityReflectionProperty->getType();
        }

        // Handling Encryption setup and errors
        /** @var DatabaseColumn $databaseColumnAttribute */
        $databaseColumnAttribute = $entityReflectionClass->getAttributeInstanceForProperty(
            $entityPropertyName,
            DatabaseColumn::class
        );

        $encryptionScopePassword = null;
        // Resolve the scope ONLY when there is actually a stored value to DECRYPT: a NULL column needs no
        // password, and resolving anyway couples every row of the entity to the encryption infrastructure —
        // live incident 2026-08-12: saving a plain owner mobile (AccountChannelAddress, whose ONLY encrypted
        // column voiceProviderRegistrations was NULL) died on the EncryptionScopes table lookup.
        if ($databaseColumnAttribute && $databaseColumnAttribute->encrypted && ($this->ormInstance->$propertyName ?? null) !== null) {
            // A human-driven request supplies the password (cookie / Messenger message) and keeps precedence; a
            // headless webhook or worker has neither and falls back to this scope's environment password.
            $encryptionPassword = Encrypt::$password
                ?: Encrypt::getEnvironmentPasswordForScope($databaseColumnAttribute->enryptionScope);
            if (!$encryptionPassword) {
                throw new UnauthorizedException(
                    'Encryption cannot be performed without an encryption password set in Encrypt class'
                );
            }
            $encryptionScopePassword = EncryptionScopes::getService()->getScopePassword(
                $encryptionPassword,
                $databaseColumnAttribute->enryptionScope
            );
            if (!$encryptionScopePassword) {
                throw new UnauthorizedException(
                    'No EncryptionScopePassword available for given encryptionPassword'
                );
            }
        }

        foreach ($possibleEntityTypes as $possibleEntityType) {
            $possibleEntityTypeName = $possibleEntityType->getName();
            // Handling of simple types in case of encryption
            if ($encryptionScopePassword) {
                // Compare the TYPE NAME, never the ReflectionNamedType object: the object's __toString() keeps
                // the nullability marker ("?string"), so a loose object comparison against 'string' silently
                // missed every NULLABLE encrypted property — the raw ciphertext then fell through to the
                // generic assignment and was served as the value.
                $decryptedString = Encrypt::decrypt($this->ormInstance->$propertyName, $encryptionScopePassword);
                if ($possibleEntityTypeName == ReflectionClass::STRING) {
                    $entity->$entityPropertyName = (string)$decryptedString;
                    continue;
                } elseif ($possibleEntityTypeName == ReflectionClass::INTEGER) {
                    $entity->$entityPropertyName = (int)$decryptedString;
                    continue;
                } elseif ($possibleEntityTypeName == ReflectionClass::FLOAT) {
                    $entity->$entityPropertyName = (float)$decryptedString;
                    continue;
                } elseif ($possibleEntityTypeName == ReflectionClass::BOOL) {
                    $entity->$entityPropertyName = (bool)$decryptedString;
                    continue;
                } elseif ($possibleEntityTypeName == DateTime::class) {
                    $entity->$entityPropertyName = DateTime::fromString($decryptedString);
                    continue;
                } elseif ($possibleEntityTypeName == Date::class) {
                    $entity->$entityPropertyName = Date::fromString($decryptedString);
                    continue;
                }
            }

            // handling cases with translation
            $translatableProperty = $entityReflectionClass->getAttributeInstanceForProperty(
                $entityPropertyName,
                Translatable::class
            );
            if ($translatableProperty) {
                /** @var TranslatableTrait $entity */
                $translationInfos = $entity->getTranslationInfos();
                $entity->setTranslationsForProperty($entityPropertyName, $this->ormInstance->$propertyName);
                return;
            }

            // trivial case, types are equal
            if ($possibleEntityTypeName == $ormModelReflectionProperty->getType()->getName()) {
                $entity->$entityPropertyName = $this->ormInstance->$propertyName;
                return;
            }
            if (
                $possibleEntityTypeName == DateTime::class && $ormModelReflectionProperty->getType()->getName() == \DateTime::class
            ) {
                $entity->$entityPropertyName = DateTime::fromTimestamp($this->ormInstance->$propertyName->getTimestamp());
            }
            if (
                $possibleEntityTypeName == Date::class && $ormModelReflectionProperty->getType()->getName() == \DateTime::class
            ) {
                $entity->$entityPropertyName = Date::fromTimestamp($this->ormInstance->$propertyName->getTimestamp());
            }
            // one to many relations implicitly loaded
            if (is_a($possibleEntityTypeName, EntitySet::class, true)) {
                if ($this->ormInstance->isLoaded($propertyName)) {
                    /** @var EntitySet $entitySetClass */
                    $entitySetClass = $possibleEntityTypeName;
                    /** @var DBEntitySet $dbEntitySetClass */
                    $dbEntitySetClass = $entitySetClass::getRepoClass(LazyLoadRepo::DB);
                    if ($dbEntitySetClass && !isset($initiatorClasses[(string)$dbEntitySetClass])) {
                        /** @var EntitySet $entitySet */
                        $entitySet = new $entitySetClass();
                        $entity->addChildren($entitySet);
                        /** @var DBEntity $dbEntityClass */
                        $dbEntityClass = $dbEntitySetClass::BASE_REPO_CLASS;
                        $dbEntity = new $dbEntityClass();
                        $initiatorClasses[$entity::class] = true;
                        /** @var DoctrineModel $dependentOrmInstance */
                        foreach ($this->ormInstance->$propertyName as $dependentOrmInstance) {
                            $dependentEntity = $dbEntity->find(
                                $dependentOrmInstance->id,
                                $useEntityRegistryCache,
                                $dependentOrmInstance,
                                false,
                                $initiatorClasses
                            );
                            $entitySet->add($dependentEntity);
                            $entitySet->addChildren($dependentEntity);
                        }
                        $entity->$entityPropertyName = $entitySet;
                        $entity->addChildren($entitySet);
                        $entitySet->setParent($entity);
                    }
                }
            } elseif (
                DefaultObject::isValueObject($possibleEntityTypeName)
            ) {
                // handle object type migrations
                /** @noinspection PhpIllegalStringOffsetInspection -- dynamic property access, is_array() guards the string offset */
                if (
                    is_array($this->ormInstance->$propertyName) && isset($this->ormInstance->$propertyName['objectType']) && isset(
                        ReflectionClass::getObjectTypeMigrations()[$this->ormInstance->$propertyName['objectType']]
                    )
                ) {
                    $this->ormInstance->$propertyName['objectType'] = ReflectionClass::getObjectTypeMigrations(
                    )[$this->ormInstance->$propertyName['objectType']];
                } elseif (
                    is_object($this->ormInstance->$propertyName) && isset($this->ormInstance->$propertyName->objectType) && isset(
                        ReflectionClass::getObjectTypeMigrations()[$this->ormInstance->$propertyName->objectType]
                    )
                ) {
                    $this->ormInstance->$propertyName->objectType = ReflectionClass::getObjectTypeMigrations()[$this->ormInstance->$propertyName->objectType];
                }
                // exact match needed, for UnionTypes so the right type gets instantiated
                if (
                    count($possibleEntityTypes) == 1 || ((is_array(
                                $this->ormInstance->$propertyName
                            ) && ($this->ormInstance->$propertyName['objectType'] ?? null) == $possibleEntityTypeName) || (is_object(
                                $this->ormInstance->$propertyName
                            ) && ($this->ormInstance->$propertyName->objectType ?? null) == $possibleEntityTypeName))
                ) {
                    /** @var ValueObject $valueObject */
                    $valueObject = new $possibleEntityTypeName();
                    // Handling ValueObjects in case of encryption
                    $propertyValue = $this->ormInstance->$propertyName;
                    if ($encryptionScopePassword) {
                        $propertyValue = Encrypt::decrypt($propertyValue, $encryptionScopePassword);
                    }
                    $valueObject->mapFromRepository($propertyValue);
                    $entity->$entityPropertyName = $valueObject;
                    $entity->addChildren($entity->$entityPropertyName);
                    $valueObject->setParent($entity);
                }
            }
            // in case that ormInstance contains initialized dependent Mode, we load it
            if (
                DefaultObject::isEntity($possibleEntityTypeName) && $this->ormInstance->isLoaded($propertyName)
            ) {
                /** @var Entity $entityType */
                $entityType = $possibleEntityTypeName;
                $repoClassName = $entityType::getRepoClass(LazyLoadRepo::DB);
                /** @var DoctrineModel $ormModelInstance */
                $ormModelInstance = $this->ormInstance->$propertyName;
                if ($repoClassName && !isset($initiatorClasses[(string)$entityType])) {
                    /** @var DBEntity $repo */
                    $repo = new $repoClassName();
                    $initiatorClasses[$entity::class] = true;
                    /** @var Entity $propertyEntity */
                    $propertyEntity = $repo->find(
                        $ormModelInstance->id,
                        $useEntityRegistryCache,
                        $ormModelInstance,
                        false,
                        $initiatorClasses
                    );
                    $entity->$entityPropertyName = $propertyEntity;
                    // check if entity needs to be added as child or as parent
                    $addAsParent = $entityReflectionClass->isLazyLoadedPropertyToBeAddedAsParent($entityPropertyName);
                    if ($addAsParent) {
                        $entity->setParent($propertyEntity);
                        $propertyEntity->addChildren($entity);
                    } else {
                        $entity->addChildren($propertyEntity);
                        $propertyEntity->setParent($entity);
                    }
                }
            }
        }
    }

    /**
     * Maps the Entity to the Repository orm instance
     * @param Entity $entity
     * @return bool
     * @throws ReflectionException
     */
    public function mapToRepository(DefaultObject &$entity, ?array $restrictToPropertyNames = null): bool
    {
        if (!DefaultObject::isEntity($entity)) {
            return false;
        }
        // Strict-partial allow-set: when $restrictToPropertyNames is given, map ONLY the id + those properties (the id
        // is always mapped — it is the row key). Used by {@see updatePartialIgnoringRights}; reuses all the per-property
        // special-type / translation handling below, scoped to the named columns. Null = map every set property (full).
        $restrictedProperties = $restrictToPropertyNames === null ? null : array_fill_keys([...$restrictToPropertyNames, 'id'], true);

        $this->ormInstance = isset($this->ormInstance) && $this->ormInstance ? $this->ormInstance : new (static::getBaseModelNameForEntityInstance(
            $entity
        ))();
        $this->mapCreatedAndUpdatedTime($entity);
        // map all fields from Entity to ormInstance to
        $mappedProperties = [];
        foreach ($entity as $propertyName => $propertyValue) {
            if ($restrictedProperties !== null && !isset($restrictedProperties[$propertyName])) {
                continue;
            }
            $this->mapPropertyToRepository($entity, $propertyName);
            $mappedProperties[$propertyName] = true;
        }

        // Also map properties that exist only in translationsStore but are not set on the entity
        if (method_exists($entity, 'getTranslationInfos') && isset($entity->translationInfos)) {
            $translationsStore = $entity->translationInfos->translationsStore ?? [];
            $entityReflectionClass = ReflectionClass::instance($entity::class);
            foreach ($translationsStore as $propertyName => $translations) {
                if ($restrictedProperties !== null && !isset($restrictedProperties[$propertyName])) {
                    continue;
                }
                if (isset($mappedProperties[$propertyName])) {
                    continue;
                }
                if (!$entityReflectionClass->hasProperty($propertyName)) {
                    continue;
                }
                $this->mapPropertyToRepository($entity, $propertyName);
            }
        }
        return true;
    }

    /**
     * High-performance PARTIAL update for high-concurrency scenarios: writes STRICTLY the named properties' columns of
     * an EXISTING row, leaving every other column untouched. Use it for competing single-column writes (run-state
     * claim/heartbeat, an async-computed summary / embedding / verdict) where the full {@see DatabaseRepoEntity::update()}
     * would clobber a column a concurrent writer just set — and where update()'s read-after-write reload
     * (`EntityManager::clear()` + `find()`) would detach the caller's live entity (breaking its lazy-load relations)
     * and pollute the entity registry.
     *
     * Strictly targeted by construction: {@see mapToRepository()} maps ONLY id + $propertyNames (reusing its
     * special-type / translation / change-history handling), and {@see DoctrineEntityManager::upsert()} is passed the
     * SAME allow-list so it writes exactly those columns — independent of the generated model's inline column defaults.
     * No rights are applied (a null rights QueryBuilder makes upsert skip the rights check entirely — it never consults
     * the rights state otherwise); this is a system write of a row the caller already loaded under rights.
     *
     * @param string ...$propertyNames Entity property names whose columns are the ONLY ones written.
     * @return int|null The row id, or null when the entity is not persisted (no id) — a partial update needs an existing row.
     * @throws ReflectionException
     */
    public function updatePartialIgnoringRights(DefaultObject &$entity, string ...$propertyNames): ?int
    {
        if (!DefaultObject::isEntity($entity) || !isset($entity->id) || !$entity->id) {
            return null;
        }
        if (!is_a($entity::class, (string)$this::BASE_ENTITY_CLASS, true)) {
            return null;
        }
        $this->ormInstance = new (static::getBaseModelNameForEntityInstance($entity))();
        $this->mapToRepository($entity, $propertyNames);
        $entityManager = EntityManagerFactory::getInstance();
        $rowId = $entityManager->upsert($this->ormInstance, null, $propertyNames);
        // The raw upsert bypasses the UnitOfWork: a model of this row that an EARLIER query hydrated stays MANAGED
        // with its pre-write field values, and every later hydration of the same row in this process (a set query, a
        // find(), even one with HINT_REFRESH) hands the stale managed instance back — so a status written a moment
        // ago reads as the old status until the process ends. Detaching that ONE instance makes the next hydration
        // read the DB. The caller's live entity is untouched, which is the whole point of the partial write (the
        // full update() path solves this with an EntityManager::clear() that the partial write must not do).
        $this->detachManagedOrmInstanceOfRow($entityManager, $this->ormInstance::class, $entity->id);
        return $rowId;
    }

    /**
     * Drops the UnitOfWork's managed instance of ONE row after a write that bypassed it, so the next hydration of
     * that row reads the DB instead of the stale identity-map copy. No-op when the row is not managed.
     *
     * The identity map is keyed by the ROOT entity class of the hierarchy, not by the concrete model: for a
     * single-table-inheritance model ({@see \DDD\Domain\Base\Repo\DB\Database\SubclassIndicator}, which
     * generates `#[ORM\InheritanceType('SINGLE_TABLE')]`) a lookup with the subclass name silently finds nothing
     * and the stale instance survives — so the class metadata resolves the root first.
     *
     * @param EntityManagerInterface $entityManager
     * @param string $ormModelClass The concrete Doctrine model class of the written row
     * @param int|string $id
     * @return void
     */
    protected function detachManagedOrmInstanceOfRow(
        EntityManagerInterface $entityManager,
        string $ormModelClass,
        int|string $id
    ): void {
        try {
            $rootOrmModelClass = $entityManager->getClassMetadata($ormModelClass)->rootEntityName ?: $ormModelClass;
            $managedOrmInstance = $entityManager->getUnitOfWork()->tryGetById(['id' => $id], $rootOrmModelClass);
            if (is_object($managedOrmInstance)) {
                $entityManager->detach($managedOrmInstance);
            }
        } catch (Throwable) {
            // fail-soft: a metadata lookup that cannot resolve the class must never break the write that happened
        }
    }

    public function mapCreatedAndUpdatedTime(DefaultObject &$entity): void
    {
        /** @var ChangeHistoryTrait $entityClass */
        $entityClass = $this->ormInstance::ENTITY_CLASS;
        /** @var ChangeHistory $changeHistoryAttributeInstance */
        if (method_exists($entityClass, 'getChangeHistoryAttribute')) {
            $changeHistoryAttributeInstance = $entityClass::getChangeHistoryAttribute(true);
            $createdColumn = $changeHistoryAttributeInstance->getCreatedColumn();
            $modifiedColumn = $changeHistoryAttributeInstance->getModifiedColumn();
            /** @var ChangeHistoryTrait $entity */
            $createdTime = null;
            if (!isset($entity->id) || (!isset($entity->changeHistory->createdTime)) || $entity->changeHistory->overwriteCreatedAndModifiedTime) {
                /** @var DateTime $enityCreatedTime */
                if (isset($entity->changeHistory->createdTime) && $entity->changeHistory->overwriteCreatedAndModifiedTime) {
                    $enityCreatedTime = $entity->changeHistory->createdTime;
                } else {
                    $enityCreatedTime = new DateTime();
                }
                if ($changeHistoryAttributeInstance->getCreatedColumnStyle() == ChangeHistory::TIMESTAMP) {
                    $createdTime = $enityCreatedTime->getTimestamp();
                } elseif ($changeHistoryAttributeInstance->getCreatedColumnStyle() == ChangeHistory::DATETIME_ATOM) {
                    $createdTime = $enityCreatedTime->format(DateTime::ATOM);
                } elseif ($changeHistoryAttributeInstance->getCreatedColumnStyle() == ChangeHistory::DATETIME_SIMPLE) {
                    $createdTime = $enityCreatedTime;
                }
            }
            $modifiedTime = null;

            /** @var DateTime $entityModifiedTime */
            if (isset($entity->changeHistory->modifiedTime) && $entity->changeHistory->overwriteCreatedAndModifiedTime) {
                $entityModifiedTime = $entity->changeHistory->modifiedTime;
            } else {
                $entityModifiedTime = new DateTime();
            }
            if ($changeHistoryAttributeInstance->getModifiedColumnStyle() == ChangeHistory::TIMESTAMP) {
                $modifiedTime = $entityModifiedTime->getTimestamp();
            } elseif ($changeHistoryAttributeInstance->getModifiedColumnStyle() == ChangeHistory::DATETIME_ATOM) {
                $modifiedTime = $entityModifiedTime->format(DateTime::ATOM);
            } elseif ($changeHistoryAttributeInstance->getModifiedColumnStyle() == ChangeHistory::DATETIME_SIMPLE) {
                $modifiedTime = $entityModifiedTime;
            }
            if ($createdTime && property_exists($this->ormInstance, $createdColumn)) {
                if (!($this->ormInstance->$createdColumn ?? null)) {
                    $this->ormInstance->$createdColumn = $createdTime;
                }
            }
            if (
                $modifiedTime && ((!$createdTime && property_exists(
                            $this->ormInstance,
                            $modifiedColumn
                        ) || (isset($entity->id) && !isset($entity->changeHistory->createdTime))) || (isset($entity->changeHistory) && $entity->changeHistory->overwriteCreatedAndModifiedTime))
            ) {
                $this->ormInstance->$modifiedColumn = $modifiedTime;
            }
        }
    }

    /**
     * Maps single property from Entity to repository
     * @param Entity $entity
     * @param string $propertyName
     * @return void
     * @throws ReflectionException
     */
    public function mapPropertyToRepository(DefaultObject &$entity, string $propertyName): void
    {
        $ormModelReflectionClass = ReflectionClass::instance($this->ormInstance::class);
        if (!$ormModelReflectionClass->hasProperty($propertyName)) {
            return;
        }
        $entityReflectionClass = ReflectionClass::instance($this->ormInstance::ENTITY_CLASS);
        $entityReflectionProperty = $entityReflectionClass->getProperty($propertyName);

        // we write the property if it is set, means it has a value or it is null
        // in case of null, we take care to check if the target value supports null
        $setProperty = false;
        if (ReflectionClass::isPropertyInitialized($entity, $propertyName)) {
            if ($entity->$propertyName === null) // id shall not be written as null ecen if we allow null on Entity->id
            {
                $setProperty = $entityReflectionProperty->allowsNull() && $propertyName != 'id';
            } else {
                $setProperty = true;
            }
        }
        // Also allow mapping if the property is not initialized but has translations in the store
        if (!$setProperty && method_exists($entity, 'getTranslationInfos') && isset($entity->translationInfos)) {
            $translationsStore = $entity->translationInfos->translationsStore ?? [];
            if (isset($translationsStore[$propertyName]) && !empty($translationsStore[$propertyName])) {
                $setProperty = true;
            }
        }
        if (!$setProperty) {
            return;
        }

        $ormModelReflectionProperty = $ormModelReflectionClass->getProperty($propertyName);

        // if attribute has lazyload on it, we do not map it to repository, it is then e.g. en EntitySet of dependent Entities
        $hasDBOrVirtualLazyloadRepo = false;
        $propertyValueIsValueObject = false;
        $propertyValueIsEntity = false;
        $propertyInitialized = ReflectionClass::isPropertyInitialized($entity, $propertyName);
        $propertyValue = $propertyInitialized ? $entity->$propertyName : null;
        $propertyValueIsValueObject = DefaultObject::isValueObject($propertyValue);
        $propertyValueIsEntity = DefaultObject::isEntity($propertyValue);

        if (
            $propertyValueIsValueObject && ($lazyloadAttributes = $entityReflectionProperty->getAttributes(
                LazyLoad::class,
                ReflectionAttribute::IS_INSTANCEOF
            ))
        ) {
            foreach ($lazyloadAttributes as $lazyloadAttribute) {
                /** @var LazyLoad $instance */
                $instance = $lazyloadAttribute->newInstance();
                if (
                    in_array(
                        $instance->repoType,
                        LazyLoadRepo::DATABASE_REPOS
                    ) || $instance->repoType == LazyLoadRepo::VIRTUAL
                ) {
                    $hasDBOrVirtualLazyloadRepo = true;
                }
            }
        }

        $ormType = $ormModelReflectionProperty->getType();

        $mappedValue = null;
        $mappedValueSet = false;

        // Handle translatable properties first.
        // Important: the entity property itself can be NULL/empty while translations are stored in TranslationInfos.
        // In that case we still must persist the translations instead of writing NULL.
        $translatableProperty = $entityReflectionClass->getAttributeInstanceForProperty(
            $propertyName,
            Translatable::class
        );
        if ($translatableProperty) {
            /** @var TranslatableTrait $entity */
            $translationInfos = $entity->getTranslationInfos();
            $mappedValue = $translationInfos->getTranslationsForProperty($propertyName, true);
            if ($mappedValue !== null) {
                $mappedValueSet = true;
            }
            // If replaceExistingTranslations is set, signal to DoctrineEntityManager to skip JSON_MERGE_PATCH for this column
            if ($translationInfos->replaceExistingTranslations) {
                $this->ormInstance->columnsToReplaceInsteadOfMerge[] = $propertyName;
            }
        }

        // handle null case (only if not already mapped via Translatable)
        if (!$mappedValueSet && $propertyInitialized && $entity->$propertyName === null) {
            $mappedValueSet = true;
        } elseif (!$mappedValueSet && $propertyInitialized) {
            if ($propertyValueIsValueObject && !$hasDBOrVirtualLazyloadRepo) {
                /** @var ValueObjectTrait $valueObject */
                $valueObject = $propertyValue;
                $mappedValue = $valueObject->mapToRepository();
                $mappedValueSet = true;
            } elseif ($ormType->isBuiltin()) {
                $value = $propertyValue;
                if ($ormType->getName() == ReflectionClass::STRING) {
                    $mappedValue = (string)$value;
                    $mappedValueSet = true;
                } elseif ($ormType->getName() == ReflectionClass::INTEGER) {
                    $mappedValue = (int)$value;
                    $mappedValueSet = true;
                } elseif ($ormType->getName() == ReflectionClass::FLOAT) {
                    $mappedValue = (float)$value;
                    $mappedValueSet = true;
                } elseif ($ormType->getName() == ReflectionClass::BOOL) {
                    $mappedValue = (bool)$value;
                    $mappedValueSet = true;
                }
            } elseif ($propertyValue instanceof \DateTime) {
                $mappedValue = $propertyValue;
                $mappedValueSet = true;
            }

        }

        // Encryption can also apply to translatable properties (mapped value may be an array)
        // so we apply it once after mapping.
        if ($mappedValueSet) {
            /** @var DatabaseColumn $databaseColumnAttribute */
            $databaseColumnAttribute = $entityReflectionClass->getAttributeInstanceForProperty(
                $propertyName,
                DatabaseColumn::class
            );
            // NULL is stored as NULL — nothing to encrypt, no scope resolution (mirror of the read-path guard:
            // a row whose encrypted column is empty must not touch the encryption infrastructure at all).
            if ($databaseColumnAttribute && $databaseColumnAttribute->encrypted && $mappedValue !== null) {
                // Same fallback as on the read path: a human-driven request keeps precedence, a headless webhook
                // or worker uses this scope's environment password.
                $encryptionPassword = Encrypt::$password
                    ?: Encrypt::getEnvironmentPasswordForScope($databaseColumnAttribute->enryptionScope);
                if (!$encryptionPassword) {
                    throw new UnauthorizedException(
                        'Encryption cannot be performed without an encryption password set in Encrypt class'
                    );
                }
                $scopePassword = EncryptionScopes::getService()->getScopePassword(
                    $encryptionPassword,
                    $databaseColumnAttribute->enryptionScope
                );
                if (!$scopePassword) {
                    throw new UnauthorizedException(
                        'No EncryptionScopePassword available for given encryptionPassword'
                    );
                }
                if (is_array($mappedValue)) {
                    $mappedValue = json_encode($mappedValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $mappedValue = Encrypt::encrypt((string)$mappedValue, $scopePassword);
            }
        }
        if ($mappedValueSet) {
            $this->ormInstance->$propertyName = $mappedValue;
        }
    }
}

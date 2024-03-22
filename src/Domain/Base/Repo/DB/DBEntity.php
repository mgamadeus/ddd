<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Common\Entities\Encryption\EncryptionScopes;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;

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
     * @param array $initiatorClasses
     * @return Entity|EntitySet|null
     * @throws ReflectionException
     */
    public function mapToEntity(
        bool $useEntityRegistryCache = true,
        array &$initiatorClasses = []
    ): ?DefaultObject {
        // on the highest level, we first clear ormInstanceToEntityAllocation
        if (empty($initiatorClasses)) {
            self::$ormInstanceToEntityAllocation = [];
        }

        /** @var ChangeHistoryTrait $entityClass */
        $entityClass = $this->ormInstance::ENTITY_CLASS;
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
            $entityInstance->changeHistory = $changeHistoryAttributeInstance;
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
        foreach ($this->ormInstance as $propertyName => $propertyValue) {
            /** @var ENtity $entityInstance */
            $this->mapPropertyToEntity($entityInstance, $propertyName, $initiatorClasses, $useEntityRegistryCache);
        }
        return $entityInstance;
    }

    /**
     * Maps single property from repository to Entity
     * @param Entity $entity
     * @param string $propertyName
     * @param array $initiatorClasses
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function mapPropertyToEntity(
        Entity &$entity,
        string $propertyName,
        array &$initiatorClasses = [],
        bool $useEntityRegistryCache = true
    ) {
        if (!isset($this->ormInstance->$propertyName)) {
            return;
        }

        $entityReflectionClass = ReflectionClass::instance($entity::class);
        if (!$entityReflectionClass->hasProperty($propertyName)) {
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
            $entity->$propertyName = $propertyEntity;
            $addAsParent = $entityReflectionClass->isLazyLoadedPropertyToBeAddedAsParent($propertyName);
            if ($addAsParent) {
                $entity->setParent($propertyEntity);
                $propertyEntity->addChildren($entity);
            } else {
                $entity->addChildren($propertyEntity);
                $propertyEntity->setParent($entity);
            }
            return;
        }

        $entityReflectionProperty = $entityReflectionClass->getProperty($propertyName);

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
            $propertyName,
            DatabaseColumn::class
        );

        $encryptionScopePassword = null;
        if ($databaseColumnAttribute && $databaseColumnAttribute->encrypted) {
            if (!Encrypt::$password) {
                throw new UnauthorizedException(
                    'Encryption cannot be performed without an encryption password set in Encrypt class'
                );
            }
            $encryptionScopePassword = EncryptionScopes::getService()->getScopePassword(
                Encrypt::$password,
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
                $decryptedString = Encrypt::decrypt($this->ormInstance->$propertyName, $encryptionScopePassword);
                if ($possibleEntityType == ReflectionClass::STRING) {
                    $entity->$propertyName = (string)$decryptedString;
                    continue;
                } elseif ($possibleEntityType == ReflectionClass::INTEGER) {
                    $entity->$propertyName = (int)$decryptedString;
                    continue;
                } elseif ($possibleEntityType == ReflectionClass::FLOAT) {
                    $entity->$propertyName = (float)$decryptedString;
                    continue;
                } elseif ($possibleEntityType == ReflectionClass::BOOL) {
                    $entity->$propertyName = (bool)$decryptedString;
                    continue;
                } elseif ($possibleEntityType == DateTime::class) {
                    $entity->$propertyName = DateTime::fromString($decryptedString);
                    continue;
                } elseif ($possibleEntityType == Date::class) {
                    $entity->$propertyName = Date::fromString($decryptedString);
                    continue;
                }
            }

            // trivial case, types are equal
            if ($possibleEntityTypeName == $ormModelReflectionProperty->getType()->getName()) {
                $entity->$propertyName = $this->ormInstance->$propertyName;
                return;
            }
            if ($possibleEntityTypeName == DateTime::class && $ormModelReflectionProperty->getType()->getName() == \DateTime::class) {
                $entity->$propertyName = DateTime::fromTimestamp($this->ormInstance->$propertyName->getTimestamp());
            }
            if ($possibleEntityTypeName == Date::class && $ormModelReflectionProperty->getType()->getName() == \DateTime::class) {
                $entity->$propertyName = Date::fromTimestamp($this->ormInstance->$propertyName->getTimestamp());
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
                        $entity->$propertyName = $entitySet;
                        $entity->addChildren($entitySet);
                        $entitySet->setParent($entity);
                    }
                }
            } elseif (
                is_a($possibleEntityTypeName, ValueObject::class, true) // exact match needed, for UnionTypes so the right type gets instantiated
                && (count($possibleEntityTypes) == 1 || ((is_array(
                                $this->ormInstance->$propertyName
                            ) && ($this->ormInstance->$propertyName['objectType'] ?? null) == $possibleEntityTypeName) || (is_object(
                                $this->ormInstance->$propertyName
                            ) && ($this->ormInstance->$propertyName->objectType ?? null) == $possibleEntityTypeName)))
            ) {
                /** @var ValueObject $valueObject */
                $valueObject = new $possibleEntityTypeName();
                // Handling ValueObjects in case of encryption
                $propertyValue = $this->ormInstance->$propertyName;
                if ($encryptionScopePassword) {
                    $propertyValue = Encrypt::decrypt($propertyValue, $encryptionScopePassword);
                }
                $valueObject->mapFromRepository($propertyValue);
                $entity->$propertyName = $valueObject;
                $entity->addChildren($entity->$propertyName);
                $valueObject->setParent($entity);
            }
            // in case that ormInstance contains initialized dependent Mode, we load it
            if (
                is_a(
                    $possibleEntityTypeName,
                    Entity::class,
                    true
                ) /*&& $this->ormInstance->$propertyName instanceof DoctrineModel */ && $this->ormInstance->isLoaded($propertyName)
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
                    $entity->$propertyName = $propertyEntity;
                    // check if entity needs to be added as child or as parent
                    $addAsParent = $entityReflectionClass->isLazyLoadedPropertyToBeAddedAsParent($propertyName);
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

    public function mapCreatedAndUpdatedTime(Entity &$entity): void
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
            if (!$entity->id || (!isset($entity->changeHistory->createdTime)) || $entity->changeHistory->overwriteCreatedAndModifiedTime) {
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
                        ) || ($entity->id && !isset($entity->changeHistory->createdTime))) || (isset($entity->changeHistory) && $entity->changeHistory->overwriteCreatedAndModifiedTime))
            ) {
                $this->ormInstance->$modifiedColumn = $modifiedTime;
            }
        }
    }

    /**
     * Maps the Entity to the Repository orm instance
     * @param Entity $entity
     * @return bool
     * @throws ReflectionException
     */
    public function mapToRepository(Entity &$entity): bool
    {
        $this->ormInstance = isset($this->ormInstance) && $this->ormInstance ? $this->ormInstance : new (static::getBaseModelNameForEntityInstance(
            $entity
        ))();
        $this->mapCreatedAndUpdatedTime($entity);
        // map all fields from Entity to ormInstance to
        foreach ($entity as $propertyName => $propertyValue) {
            $this->mapPropertyToRepository($entity, $propertyName);
        }
        return true;
    }

    /**
     * Maps single property from Entity to repository
     * @param Entity $entity
     * @param string $propertyName
     * @return void
     * @throws ReflectionException
     */
    public function mapPropertyToRepository(Entity &$entity, string $propertyName)
    {
        if (!isset($entity->$propertyName)) {
            return;
        }
        $ormModelReflectionClass = ReflectionClass::instance($this->ormInstance::class);
        if (!$ormModelReflectionClass->hasProperty($propertyName)) {
            return;
        }
        $ormModelReflectionProperty = $ormModelReflectionClass->getProperty($propertyName);

        $entityReflectionClass = ReflectionClass::instance($this->ormInstance::ENTITY_CLASS);
        $entityReflectionProperty = $entityReflectionClass->getProperty($propertyName);

        // if attribute has lazyload on it, we do not map it to repository, it is then e.g. en EntitySet of dependent Entities
        $hasDBOrVirtualLazyloadRepo = false;
        if (
            $entity->$propertyName instanceof ValueObject && ($lazyloadAttributes = $entityReflectionProperty->getAttributes(
                LazyLoad::class
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

        if ($entity->$propertyName instanceof ValueObject && !$hasDBOrVirtualLazyloadRepo) {
            /** @var ValueObject $valueObject */
            $valueObject = $entity->$propertyName;
            $mappedValue = $valueObject->mapToRepository();
            $mappedValueSet = true;
        } elseif ($ormType->isBuiltin()) {
            $value = $entity->$propertyName;
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
        } elseif ($entity->$propertyName instanceof \DateTime) {
            $mappedValue = $entity->$propertyName;
            $mappedValueSet = true;
        }

        $translatableProperty = $entityReflectionClass->getAttributeInstanceForProperty($propertyName, Translatable::class);
        if ($translatableProperty) {
            /** @var TranslatableTrait $entity */
            $translationInfos = $entity->getTranslationInfos();
            $mappedValue = $translationInfos->getTranslationsForProperty($propertyName);
            if ($mappedValue !== null) {
                $mappedValueSet = true;
            }
        }

        // if column is encrypted, we encrypt the value using the scope password
        /** @var DatabaseColumn $databaseColumnAttribute */
        $databaseColumnAttribute = $entityReflectionClass->getAttributeInstanceForProperty(
            $propertyName,
            DatabaseColumn::class
        );
        if ($databaseColumnAttribute && $databaseColumnAttribute->encrypted) {
            if (!Encrypt::$password) {
                throw new UnauthorizedException(
                    'Encryption cannot be performed without an encryption password set in Encrypt class'
                );
            }
            $scopePassword = EncryptionScopes::getService()->getScopePassword(
                Encrypt::$password,
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
        if ($mappedValueSet) {
            $this->ormInstance->$propertyName = $mappedValue;
        }
    }
}
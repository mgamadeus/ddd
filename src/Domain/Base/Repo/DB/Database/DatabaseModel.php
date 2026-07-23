<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use Doctrine\ORM\Mapping\Table;
use ReflectionAttribute;
use ReflectionException;
use ReflectionNamedType;

class DatabaseModel extends ValueObject
{
    public const string DEFAULT_COLLATION = 'utf8mb4_unicode_ci';

    public const string MODEL_SUFFIX = 'Model';

    /**
     * @var string|null Optional: If Entity inherits another Entity, then, a Database Model is generated that inherits the one of the parent Entity, but no SQL Table.
     * In this case Single Table Inheritance definitions is applied to the parent's class Database Model
     */
    public ?ClassWithNamespace $parentEntityCLassWithNamespace = null;

    /**
     * Used to define which Class is instantiated when loading from DB based on the value of the property
     * this Attribute is attached to.
     * E.g. Posts has property $type
     * when type == Post::POST => Post is instantiated,
     * when type == Post::EVENT => Event is instantiated
     *
     * while Event beeing a subclass of Post.
     *
     * When an Entity has a property with a SubclassIndicator, then the Doctrine Model for Event extends the Doctrine Model of Post
     * and the SQL generated  for the Table of Posts contains all fields from all subclasses.
     */
    public ?SubclassIndicator $subclassIndicator = null;

    /** @var string|null The ClassWithNamespace of the Entity class */
    public ?ClassWithNamespace $entityClassWithNamespace;

    /** @var string|null The ClassWithNamespace of the EntitySet class */
    public ?ClassWithNamespace $entitySetClassWithNamespace;

    /** @var string|null The Name of the database model */
    public ?string $name;

    /** @var string|null The ClassWithNamespace of the doctrine model */
    public ?ClassWithNamespace $modelClassWithNamespace;

    /** @var string|null The Name of the SQL table */
    public ?string $sqlTableName;

    /** @var DatabaseColumns|null Database columns */
    public ?DatabaseColumns $columns;

    /** @var DatabaseVirtualColumns|null Database virtual columns */
    public ?DatabaseVirtualColumns $virtualColumns;

    /** @var DatabaseIndexes|null Database indexes */
    public ?DatabaseIndexes $indexes;

    /** @var DatabaseIndexes|null Database indexes */
    public ?DatabaseForeignKeys $foreignKeys;

    /** @var DatabaseOneToManyRelationships|null Database one to many relationships */
    public ?DatabaseOneToManyRelationships $oneToManyRelationships;

    /** @var DatabaseOneToOneInverseRelationships|null Inverse side of bidirectional 1:1 relations (FK on the target) */
    public ?DatabaseOneToOneInverseRelationships $oneToOneInverseRelationships;

    /** @var DatabaseTriggers|null Database Triggers */
    public ?DatabaseTriggers $triggers;

    /** @var DatabaseModelImports|null Database model imports (used for Doctrine model generation) */
    public ?DatabaseModelImports $modelImports;

    /** @var string The Table's collation */
    public string $collation = self::DEFAULT_COLLATION;

    /** @var ReflectionProperty[] */
    public $potentialOneToManyRelationships = [];

    /** @var ReflectionProperty[] Single-Entity #[LazyLoad] inverse props (no own {name}Id) — candidate 1:1 inverses */
    public $potentialOneToOneInverseRelationships = [];

    /**
     * Resolves the SQL table name for an Entity class. A {@see DatabaseTableName} attribute on the Entity
     * (preferred) or its EntitySet overrides the default `DATABASE_TABLE_PREFIX` + EntitySet-name convention
     * with a verbatim name. Used for both the entity's own table and foreign-key references pointing to it.
     *
     * @param string $entityClassName
     * @return string
     * @throws ReflectionException
     */
    protected static function resolveSqlTableName(string $entityClassName): string
    {
        $entityReflectionClass = ReflectionClass::instance($entityClassName);
        /** @var Entity $entityClassName */
        $entitySetClass = $entityClassName::getEntitySetClass();
        $entitySetReflectionClass = $entitySetClass ? ReflectionClass::instance($entitySetClass) : null;

        /** @var DatabaseTableName|null $tableNameOverride */
        $tableNameOverride = $entityReflectionClass->getAttributeInstance(DatabaseTableName::class)
            ?? $entitySetReflectionClass?->getAttributeInstance(DatabaseTableName::class);
        if ($tableNameOverride !== null) {
            return $tableNameOverride->name;
        }

        $entitySetName = $entitySetReflectionClass?->getClassWithNamespace()->name
            ?? $entityReflectionClass->getClassWithNamespace()->name;
        return Config::getEnv('DATABASE_TABLE_PREFIX') . $entitySetName;
    }

    /**
     * Generates DatabaseModel from Entity class definitions
     * @param string $entityClassName
     * @return DatabaseModel
     * @throws ReflectionException
     */
    public static function fromEntityClass(string $entityClassName): ?DatabaseModel
    {
        $databaseModel = new DatabaseModel();
        $databaseModel->columns = new DatabaseColumns();
        $entityReflectionClass = ReflectionClass::instance($entityClassName);
        $databaseModel->entityClassWithNamespace = $entityReflectionClass->getClassWithNamespace();

        /** @var Entity $entityClassName */
        $entitySetClass = $entityClassName::getEntitySetClass();
        if (!$entitySetClass) {
            throw new InternalErrorException(
                'No EntitySet Class found for ' . $databaseModel->entityClassWithNamespace->name
            );
        }
        $entitySetReflectionClass = ReflectionClass::instance($entitySetClass);
        $databaseModel->entitySetClassWithNamespace = $entitySetReflectionClass->getClassWithNamespace();
        $databaseModel->name = $databaseModel->entityClassWithNamespace->name;
        $databaseModel->modelClassWithNamespace = $databaseModel->getModelClassNameWithNameSpace();

        $databaseModel->sqlTableName = self::resolveSqlTableName($entityClassName);
        $databaseModel->columns = new DatabaseColumns();
        $databaseModel->virtualColumns = new DatabaseVirtualColumns();
        $databaseModel->indexes = new DatabaseIndexes();
        $databaseModel->foreignKeys = new DatabaseForeignKeys();
        $databaseModel->triggers = new DatabaseTriggers();
        $databaseModel->modelImports = new DatabaseModelImports();

        // first we determine if the class is a subclass of an Entity
        // if we have a subclass, then we consider only elements from current class
        $reflectionProperties = [];
        if ($parentEntityClassName = $entityClassName::getParentEntityClassName()) {
            $parentEntityReflectionClass = ReflectionClass::instance($parentEntityClassName);

            $databaseModel->parentEntityCLassWithNamespace = $parentEntityReflectionClass->getClassWithNamespace();
            $reflectionProperties = $entityReflectionClass->getPropertiesOfCurrentClass(ReflectionProperty::IS_PUBLIC);

            // we need to add the discriminator column as well if we have a subclass assign it the correct default value for this Entity
            // for this, we need to get the SubclassIndicator of the parent Class and store it on this DatabaseModel

            foreach (
                $parentEntityReflectionClass->getProperties(
                    ReflectionProperty::IS_PUBLIC
                ) as $parentClassReflectionProperty
            ) {
                if (
                    $subclassIndicatorAttibuteInstance = $parentClassReflectionProperty->getAttributeInstance(
                        SubclassIndicator::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    )
                ) {
                    $reflectionProperties[] = $parentClassReflectionProperty;
                    /** @var SubclassIndicator $subclassIndicatorAttibuteInstance */
                    $subclassIndicatorAttibuteInstance->indicatorPropertyName = $parentClassReflectionProperty->getName();
                    $databaseModel->subclassIndicator = $subclassIndicatorAttibuteInstance;
                    // if one of the subclass indicator model classes has a different namespace than current model, we need to add it as import
                    $modelImportsFromSubclassIndicators = $subclassIndicatorAttibuteInstance->getDatabaseModelImportsBasedOnSubclassIndicatorsForDatabaseModel(
                        $databaseModel
                    );
                    $databaseModel->modelImports->mergeFromOtherSet($modelImportsFromSubclassIndicators);
                }
            }
        } else {
            $reflectionProperties = $entityReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);
            // first we go through all properties to check if we find a SubclassIndicator
            // as the SubclassIndicator has to be defined only in the parent class, this is checked only in case of not handling a subclass
            foreach ($reflectionProperties as $reflectionProperty) {
                // if we have a SubclassIndicator, we attach the property name and and the SubclassIndicator to the DatabaseModel
                if (
                    $subclassIndicatorAttibuteInstance = $reflectionProperty->getAttributeInstance(
                        SubclassIndicator::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    )
                ) {
                    /** @var SubclassIndicator $subclassIndicatorAttibuteInstance */
                    $subclassIndicatorAttibuteInstance->indicatorPropertyName = $reflectionProperty->getName();
                    $databaseModel->subclassIndicator = $subclassIndicatorAttibuteInstance;
                }
            }
        }

        // if we have a SubclassIndicator, we need to consider properties from all subclasses
        if ($databaseModel->subclassIndicator && !$databaseModel->parentEntityCLassWithNamespace) {
            foreach ($databaseModel->subclassIndicator->indicators as $proeprtyValue => $subclassName) {
                $subclassReflectionClass = ReflectionClass::instance($subclassName);
                // we add all exclusive properties of subclass to reflectionproperties, as
                $reflectionProperties = array_merge(
                    $reflectionProperties,
                    $subclassReflectionClass->getPropertiesOfCurrentClass(ReflectionProperty::IS_PUBLIC)
                );
            }
        }
        // First we sort reflectionProperties so that we have Entities at the end, as Entities are mapped to Foreign Keys and we rely on some internal columns that have to be created first
        usort($reflectionProperties, function (ReflectionProperty $a, ReflectionProperty $b) {
            $aRepresentsEntity = $a->getType() instanceof ReflectionNamedType && DefaultObject::isEntity($a->getType()->getName());
            $bRepresentsEntity = $b->getType() instanceof ReflectionNamedType && DefaultObject::isEntity($b->getType()->getName());
            // Sort: non-entities ($aRepresentsEntity = false) before entities ($aRepresentsEntity = true)
            if ($aRepresentsEntity && !$bRepresentsEntity) {
                return 1; // $a is an entity and should be after $b
            } elseif (!$aRepresentsEntity && $bRepresentsEntity) {
                return -1; // $b is an entity and should be after $a
            } else {
                return 0; // both are either entities or non-entities, order stays the same
            }
        });
        foreach ($reflectionProperties as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            /** @var Translatable|null $translatableAttributeInstance */
            $translatableAttributeInstance = $reflectionProperty->getAttributeInstance(
                Translatable::class,
                ReflectionAttribute::IS_INSTANCEOF
            );
            // create regular columns
            $databaseColumn = DatabaseColumn::createFromReflectionProperty($entityReflectionClass, $reflectionProperty);
            $virtualColumnsForProperty = null;
            if ($databaseColumn) {
                $databaseModel->columns->add($databaseColumn);

                // Translatable(fullTextIndex: true) => add stored virtual search column + FULLTEXT index.
                if ($translatableAttributeInstance?->fullTextIndex) {
                    $virtualSearchColumnName = Translatable::getFullTextSearchVirtualColumnName($databaseColumn->name);

                    $virtualSearchAsTemplate = <<<'SQL'
                        (
                          CASE
                            WHEN %1$s IS NULL OR JSON_VALID(%1$s) = 0 THEN ''
                            ELSE REGEXP_REPLACE(
                              TRIM(
                                BOTH ' '
                                FROM REGEXP_REPLACE(
                                  REGEXP_REPLACE(JSON_UNQUOTE(%1$s), '^\\{\\s*|\\s*\\}\\s*$', ''),
                                  '"[^"]+"\\s*:\\s*"([^"]*)"\\s*(,\\s*)?',
                                  '\\1 | '
                                )
                              ),
                              '\\s*\\|\\s*$', ''
                            )
                          END
                        )
                        SQL;
                    $virtualSearchAs = sprintf($virtualSearchAsTemplate, $databaseColumn->name);

                    // Define the type of the virtual column as TEXT (not JSON)
                    $virtualSearchReferenceColumn = clone $databaseColumn;
                    $virtualSearchReferenceColumn->name = $databaseColumn->name . 'Search';
                    $virtualSearchReferenceColumn->sqlType = DatabaseColumn::SQL_TYPE_TEXT;
                    $virtualSearchReferenceColumn->phpType = 'string';
                    $virtualSearchReferenceColumn->isBuildinType = true;
                    $virtualSearchReferenceColumn->isMergableJSONColumn = false;

                    $virtualSearchColumn = new DatabaseVirtualColumn(
                        as: $virtualSearchAs, stored: true, createIndex: false
                    );
                    $virtualSearchColumn->referenceColumn = $virtualSearchReferenceColumn;
                    $virtualSearchColumn->virtualColumnNameOverride = $virtualSearchColumnName;
                    $databaseModel->virtualColumns->add($virtualSearchColumn);

                    $virtualSearchReferencecolumnDBIndex = new DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT, indexColumns: [$virtualSearchColumnName]);
                    $databaseModel->indexes->add($virtualSearchReferencecolumnDBIndex);
                }
                if ($virtualColumnAttributes = $reflectionProperty->getAttributes(DatabaseVirtualColumn::class, ReflectionAttribute::IS_INSTANCEOF)) {
                    foreach ($virtualColumnAttributes as $virtualColumnAttribute) {
                        /** @var DatabaseVirtualColumn $virtualColumnAttributeInstance */
                        $virtualColumnAttributeInstance = $virtualColumnAttribute->newInstance();
                        $virtualColumnAttributeInstance->referenceColumn = $databaseColumn;
                        $databaseModel->virtualColumns->add($virtualColumnAttributeInstance);
                        if (!isset($databaseColumn->virtualColumnsBasedOnCurrentColumn)) {
                            $databaseColumn->virtualColumnsBasedOnCurrentColumn = new DatabaseVirtualColumns();
                            $databaseColumn->virtualColumnsBasedOnCurrentColumn->add($virtualColumnAttributeInstance);
                        }
                        if (!$virtualColumnAttributeInstance->createIndex) {
                            continue;
                        }
                        // Handle DB Indexes for Virtual Columns:
                        $indexAttributes = $reflectionProperty->getAttributes(DatabaseIndex::class, ReflectionAttribute::IS_INSTANCEOF);
                        if (count($indexAttributes)) {
                            foreach ($indexAttributes as $indexAttribute) {
                                /** @var DatabaseIndex $indexAttributeInstance */
                                $indexAttributeInstance = $indexAttribute->newInstance();
                                if ($indexAttributeInstance->indexType != DatabaseIndex::TYPE_NONE) {
                                    $indexAttributeInstance->indexColumns = [
                                        $virtualColumnAttributeInstance->getName()
                                    ];
                                    $databaseModel->indexes->add($indexAttributeInstance);
                                }
                            }
                        } elseif ($databaseColumn) {
                            // We create indexes for virtual columns even if column itself is ignored!!!
                            $index = new DatabaseIndex(indexColumns: [$virtualColumnAttributeInstance->getName()]);
                            $databaseModel->indexes->add($index);
                        }
                    }
                }
            }

            // special handling for ChangeHistory columns
            if ($reflectionProperty->getName() == 'changeHistory') {
                $databaseColumn = new DatabaseColumn();
                $databaseColumn->name = ChangeHistory::DEFAULT_CREATED_COLUMN_NAME;
                $databaseColumn->sqlType = DatabaseColumn::SQL_TYPE_ALLOCATION[DateTime::class];
                $databaseColumn->phpType = DateTime::class;
                $databaseColumn->allowsNull = true;
                $index = new DatabaseIndex(indexColumns: [ChangeHistory::DEFAULT_CREATED_COLUMN_NAME]);
                $databaseModel->indexes->add($index);
                $databaseModel->columns->add($databaseColumn);
                $databaseColumn = new DatabaseColumn();
                $databaseColumn->name = ChangeHistory::DEFAULT_MODIFIED_COLUMN_NAME;
                $databaseColumn->sqlType = DatabaseColumn::SQL_TYPE_ALLOCATION[DateTime::class];
                $databaseColumn->phpType = DateTime::class;
                $databaseColumn->allowsNull = true;
                $databaseModel->columns->add($databaseColumn);
                $index = new DatabaseIndex(indexColumns: [ChangeHistory::DEFAULT_MODIFIED_COLUMN_NAME]);
                $databaseModel->indexes->add($index);
            }
            if ($databaseColumn && !$databaseColumn->isPrimaryKey) {
                // handle indexes
                $indexAttributes = $reflectionProperty->getAttributes(DatabaseIndex::class, ReflectionAttribute::IS_INSTANCEOF);
                if (count($indexAttributes)) {
                    foreach ($indexAttributes as $indexAttribute) {
                        /** @var DatabaseIndex $indexAttributeInstance */
                        $indexAttributeInstance = $indexAttribute->newInstance();

                        // JSON-backed Translatable columns cannot have FULLTEXT index directly.
                        // If Translatable(fullTextIndex: true) is enabled, the FULLTEXT index is created on the virtual search column instead.
                        if ($translatableAttributeInstance?->fullTextIndex && $indexAttributeInstance->indexType === DatabaseIndex::TYPE_FULLTEXT) {
                            continue;
                        }
                        // MySQL/MariaDB reject SPATIAL indexes on nullable columns (error 1252).
                        // Silently drop the index on nullable spatial properties so the schema
                        // generator produces a valid CREATE TABLE — caller can either add
                        // #[NotNull] to opt back into the index, or accept that the column has
                        // no index. Matches the FULLTEXT-on-JSON suppression above.
                        if ($indexAttributeInstance->indexType === DatabaseIndex::TYPE_SPATIAL && $databaseColumn->allowsNull) {
                            continue;
                        }
                        if ($indexAttributeInstance->indexType != DatabaseIndex::TYPE_NONE) {
                            $indexAttributeInstance->indexColumns = [$reflectionProperty->getName()];
                            $databaseModel->indexes->add($indexAttributeInstance);
                        }
                    }
                } elseif (isset($databaseColumn->sqlType) && $databaseColumn->hasIndex && !$databaseColumn->ignoreProperty) {
                    $indexType = DatabaseColumn::SQL_TYPES_TO_DEFAULT_INDEX_TYPE_ALLOCATIONS[$databaseColumn->sqlType];
                    // MySQL/MariaDB reject SPATIAL indexes on nullable columns (error 1252).
                    // Same guard as the explicit-#[DatabaseIndex] branch above — silently drop
                    // the auto-allocated SPATIAL index when the column is nullable. Caller can
                    // add #[NotNull] to opt back into the index, or accept that the column has
                    // no index.
                    if ($indexType === DatabaseIndex::TYPE_SPATIAL && $databaseColumn->allowsNull) {
                        $indexType = DatabaseIndex::TYPE_NONE;
                    }
                    if ($indexType != DatabaseIndex::TYPE_NONE) {
                        $index = new DatabaseIndex(indexColumns: [$databaseColumn->name], indexType: $indexType);
                        $databaseModel->indexes->add($index);
                    }
                }
            }

            // handle indexes added to potentialOneToManyPropertyNames and processed later
            // in order to avoid recursion, we need to process first all Classes and then go through them and
            // add one to many relationsships later
            if (
                $reflectionProperty->getType() instanceof ReflectionNamedType && is_a(
                    $reflectionProperty->getType()->getName(),
                    EntitySet::class,
                    true
                ) && $lazyLoadAttributes = $reflectionProperty->getAttributes(LazyLoad::class, ReflectionAttribute::IS_INSTANCEOF)
            ) {
                foreach ($lazyLoadAttributes as $lazyLoadAttribute) {
                    /** @var LazyLoad $lazyLoadAttributeInstance */
                    $lazyLoadAttributeInstance = $lazyLoadAttribute->newInstance();
                    // right repo type found, we add property to potential one-to_many properties, to be checked later
                    if ($lazyLoadAttributeInstance->repoType == LazyLoadRepo::DB) {
                        $databaseModel->potentialOneToManyRelationships[] = $reflectionProperty;
                    }
                }
            }

            // Entities are translated to foreign keys, if they have a DB related Repo
            if (
                $reflectionProperty->getType() instanceof ReflectionNamedType && DefaultObject::isEntity($reflectionProperty->getType()->getName())
            ) {
                $propertyLazyLoadAttributes = $reflectionProperty->getAttributes(LazyLoad::class, ReflectionAttribute::IS_INSTANCEOF);
                $propertyDBRepoLazyloadAttribute = null;
                $propertyRepresentsParentClass = false;
                foreach ($propertyLazyLoadAttributes as $propertyLazyLoadAttribute) {
                    /** @var LazyLoad $propertyLazyLoadAttributeInstance */
                    $propertyLazyLoadAttributeInstance = $propertyLazyLoadAttribute->newInstance();
                    $propertyRepresentsParentClass = $propertyRepresentsParentClass || $propertyLazyLoadAttributeInstance->addAsParent;
                    if ($propertyLazyLoadAttributeInstance->repoType == LazyLoadRepo::DB) {
                        $propertyDBRepoLazyloadAttribute = $propertyLazyLoadAttributeInstance;
                        break;
                    }
                }

                /** @var Entity $foreignClassName */
                $foreignClassName = $reflectionProperty->getType()->getName();
                $foreignClassReflectionClass = ReflectionClass::instance((string)$foreignClassName);
                $hasDBRelatedRepo = false;
                foreach ($foreignClassReflectionClass->getAttributes(LazyLoadRepo::class, ReflectionAttribute::IS_INSTANCEOF) as $repoAttribute) {
                    /** @var LazyLoadRepo $repoAttributeInstance */
                    $repoAttributeInstance = $repoAttribute->newInstance();
                    if (in_array($repoAttributeInstance->repoType, LazyLoadRepo::DATABASE_REPOS)) {
                        $hasDBRelatedRepo = true;
                    }
                }
                // we create foreign keys only for Entities which have a DB related repo (we skip Argus only Entities)
                if (!$hasDBRelatedRepo) {
                    continue;
                }
                $foreignClassWithNamespace = $foreignClassReflectionClass->getClassWithNamespace();
                if (!($foreignEntitySetClassName = $foreignClassName::getEntitySetClass())) {
                    throw new InternalErrorException('EntitySet Class not found for ' . $foreignClassName);
                }
                $foreignEntitySetReflectionClass = ReflectionClass::instance($foreignEntitySetClassName);
                $foreignEntitySetClassWithNamespace = $foreignEntitySetReflectionClass->getClassWithNamespace();
                // we assume index column to be entityName + 'Id'
                $internalColumn = $reflectionProperty->getName() . 'Id';

                // if internal column is present we can create a foreign key
                if ($entityReflectionClass->hasProperty($internalColumn)) {
                    // if we find a Legacy Repo on the foreign Entity class
                    // and the property referencing the Entity Class does not have a DB Repo repo set which is also present in the Entity class
                    // => we use the model from the Legacy DB Class
                    if (
                        ($legacyDBEntity = $foreignClassName::getRepoClass(
                            LazyLoadRepo::LEGACY_DB
                        )) && !($propertyDBRepoLazyloadAttribute && $foreignClassName::getRepoClass(LazyLoadRepo::DB))
                    ) {
                        /** @var DatabaseRepoEntity $legacyDBEntity */
                        $foreignModelReflectionClass = ReflectionClass::instance($legacyDBEntity::BASE_ORM_MODEL);
                        $foreignModelClassWithNamespace = $foreignModelReflectionClass->getClassWithNamespace();
                        $foreignModelClassName = $foreignModelClassWithNamespace->name;
                        $modelImport = new DatabaseModelImport(
                            $foreignModelClassWithNamespace, $databaseModel->modelClassWithNamespace->namespace
                        );
                        $databaseModel->modelImports->add($modelImport);
                        $tableAttributeInstance = $foreignModelReflectionClass->getAttributeInstance(Table::class, ReflectionAttribute::IS_INSTANCEOF);
                        if (!$tableAttributeInstance) {
                            throw new InternalErrorException(
                                "Model $foreignModelClassName has no ORM\Table attribute"
                            );
                        }
                        /** @var Table $tableAttributeInstance */
                        $foreignTableName = $tableAttributeInstance->name;
                    } else {
                        $foreignModelClassWithNamespace = self::getModelClassWithNamespaceForEntityClassWithNamespace(
                            $foreignClassWithNamespace
                        );
                        $foreignModelClassName = $foreignModelClassWithNamespace->name;
                        $foreignTableName = self::resolveSqlTableName((string)$foreignClassName);

                        // foreign model is from different namespace, we need to add an import
                        if ($databaseModel->modelClassWithNamespace->namespace != $foreignModelClassWithNamespace->namespace) {
                            $modelImport = new DatabaseModelImport(
                                $foreignModelClassWithNamespace, $databaseModel->modelClassWithNamespace->namespace
                            );
                            $databaseModel->modelImports->add($modelImport);
                        }
                    }

                    $internalColumnProperty = $entityReflectionClass->getProperty($internalColumn);
                    $foreignKey = null;

                    // if we find an DatabaseForeignKey attribute, we use it
                    $defaultOnDeleteAction = $propertyRepresentsParentClass ? DatabaseForeignKey::ACTION_CASCADE : DatabaseForeignKey::ACTION_SET_NULL;
                    $foreignKey = $reflectionProperty->getAttributeInstance(
                        DatabaseForeignKey::class
                    ) ?? new DatabaseForeignKey(onDeleteAction: $defaultOnDeleteAction);
                    if ($propertyRepresentsParentClass) {
                        $foreignKey->representsParentRelation = true;
                    }

                    if (
                        ($foreignKey->onUpdateAction == $foreignKey::ACTION_SET_NULL || $foreignKey->onDeleteAction == $foreignKey::ACTION_SET_NULL) && !$internalColumnProperty->getType(
                        )->allowsNull()
                    ) {
                        throw new InternalErrorException(
                            "$entityClassName.$internalColumn does not allow null,
                        but foreign reference definitions applied by attribute on $entityClassName.{$reflectionProperty->getName()} define SET NULL on DELTE or UPDATE"
                        );
                    }
                    // MariaDB/MySQL forbid a FK with a CASCADE / SET NULL / SET DEFAULT referential action when the FK
                    // column is the BASE column of a STORED generated (virtual) column: the action would have to
                    // update/clear the base column that the stored expression is derived from. It fails with
                    // "[HY000][1901] … cannot be used in the GENERATED ALWAYS AS clause" on UPDATE and
                    // "[0A000][138] Cannot add foreign key on the base column of stored column" on DELETE. Only
                    // RESTRICT / NO ACTION are permitted. So when this FK's column backs a STORED virtual column,
                    // downgrade BOTH actions (update AND delete) to RESTRICT; explicit RESTRICT / NO ACTION pass through.
                    $internalDatabaseColumnInstance = $databaseModel->columns->getColumnByName($internalColumn);
                    if (isset($internalDatabaseColumnInstance->virtualColumnsBasedOnCurrentColumn)) {
                        $hasStoredVirtualColumn = false;
                        foreach (
                            $internalDatabaseColumnInstance->virtualColumnsBasedOnCurrentColumn->getElements() as $dependentDatabaseVirtualColumn
                        ) {
                            if ($dependentDatabaseVirtualColumn->stored) {
                                $hasStoredVirtualColumn = true;
                                break;
                            }
                        }
                        if ($hasStoredVirtualColumn) {
                            $actionsDisallowedOnStoredColumnBase = [
                                DatabaseForeignKey::ACTION_CASCADE,
                                DatabaseForeignKey::ACTION_SET_NULL,
                                DatabaseForeignKey::ACTION_SET_DEFAULT,
                            ];
                            if (in_array($foreignKey->onUpdateAction, $actionsDisallowedOnStoredColumnBase, true)) {
                                $foreignKey->onUpdateAction = DatabaseForeignKey::ACTION_RESTRICT;
                            }
                            if (in_array($foreignKey->onDeleteAction, $actionsDisallowedOnStoredColumnBase, true)) {
                                $foreignKey->onDeleteAction = DatabaseForeignKey::ACTION_RESTRICT;
                            }
                        }
                    }
                    $foreignKey->internalColumn = $reflectionProperty->getName();
                    $foreignKey->internalIdColumn = $internalColumn;
                    $foreignKey->foreignTable = $foreignTableName;
                    $foreignKey->foreignModelClassName = $foreignModelClassName;
                    $databaseModel->foreignKeys->add($foreignKey);
                } elseif ($propertyDBRepoLazyloadAttribute) {
                    // No own {name}Id column ⇒ single-Entity #[LazyLoad] property is the INVERSE side of a relation
                    // whose FK lives on the TARGET. If the target's FK back to us is UNIQUE it is a 1:1; resolved/
                    // filtered later in getOneToOneInverseRelationships() (mirrors potentialOneToManyRelationships
                    // for EntitySets).
                    $databaseModel->potentialOneToOneInverseRelationships[] = $reflectionProperty;
                }
            }
        }

        foreach ($databaseModel->foreignKeys->getElements() as $foreignKey) {
            // assure we create indexes for all foreign keys
            if (!$databaseModel->indexes->getIndexForSingleColumnName($foreignKey->internalIdColumn)) {
                $databaseIndex = new DatabaseIndex(indexColumns: [$foreignKey->internalIdColumn]);
                $databaseModel->indexes->add($databaseIndex);
            }
            // assure all foreign key columns are unsigned
            $databaseModel->columns->getColumnByName($foreignKey->internalIdColumn)->isUnsigned = true;
        }

        // handle indexes over multiple columns
        foreach ($entityReflectionClass->getAttributes(DatabaseIndex::class, ReflectionAttribute::IS_INSTANCEOF) as $indexAttribute) {
            /** @var DatabaseIndex $indexAttributeInstance */
            $indexAttributeInstance = $indexAttribute->newInstance();
            $databaseModel->indexes->add($indexAttributeInstance);
        }

        // handle triggers
        foreach ($entityReflectionClass->getAttributes(DatabaseTrigger::class, ReflectionAttribute::IS_INSTANCEOF) as $triggerAttribute) {
            /** @var DatabaseTrigger $triggerAttributeInstance */
            $triggerAttributeInstance = $triggerAttribute->newInstance();
            $databaseModel->triggers->add($triggerAttributeInstance);
        }
        return $databaseModel;
    }

    /**
     * @return ClassWithNamespace Returns Model ClassWithNamespace
     */
    public function getModelClassNameWithNameSpace(): ClassWithNamespace
    {
        if (isset($this->modelClassWithNamespace)) {
            return $this->modelClassWithNamespace;
        }
        $this->modelClassWithNamespace = self::getModelClassWithNamespaceForEntityClassWithNamespace(
            $this->entityClassWithNamespace
        );
        return $this->modelClassWithNamespace;
    }

    /**
     * Returns new ModelClassWithNamespace based on Entity ClassWithNamespace
     * @param ClassWithNamespace $entityClassWithNamespace
     * @return ClassWithNamespace
     */
    public static function getModelClassWithNamespaceForEntityClassWithNamespace(
        ClassWithNamespace $entityClassWithNamespace
    ): ClassWithNamespace {
        $namespace = str_replace('\\Entities\\', '\\Repo\\DB\\', $entityClassWithNamespace->namespace);
        $className = 'DB' . $entityClassWithNamespace->name . 'Model';
        $pathParts = explode('/', $entityClassWithNamespace->filename);
        $filenamePart = array_pop($pathParts); // Get the last part which is the filename
        $filenameWithoutExtension = explode('.', $filenamePart)[0];
        $newFilename = 'DB' . $filenameWithoutExtension . 'Model.php';
        // Replace the filename part in the path
        $pathParts[] = $newFilename;

        // Rebuild the path with the new filename
        $newFullFileName = implode('/', $pathParts);

        // Special handling for subfolders/subnamespaces if needed
        $newFullFileName = str_replace('/Entities/', '/Repo/DB/', $newFullFileName);

        $modelClassWithNamespace = new ClassWithNamespace($className, $namespace, $newFullFileName);#
        return $modelClassWithNamespace;
    }

    /**
     * Generates SQL code for generation of table based on Entity class definitions
     * @return string
     * @throws ReflectionException
     */
    public function getSql(): string
    {
        // if we have a parentEntityClass, than we do not create an SQL table for current entity, as the parent Entity's table has all properties of it subclasses
        if ($this->parentEntityCLassWithNamespace) {
            return '';
        }

        $sql = "#################### $this->sqlTableName ####################\n";
        $statements = [];
        $databaseColumns = $this->columns->getElements();
        usort($databaseColumns, function (DatabaseColumn $a, DatabaseColumn $b) {
            if ($a->name == 'id' && $b->name != 'id') {
                return -1;
            } elseif ($b->name == 'id' && $a->name != 'id') {
                return 1;
            } else {
                return 0;
            }
        });
        foreach ($this->columns->getElements() as $column) {
            $columnSQL = $column->getSql();
            if ($columnSQL !== null) {
                $statements[] = $columnSQL;
            }
        }
        if ($primaryKey = $this->columns->getPrimaryKeyColumn()) {
            $statements[] = "PRIMARY KEY (`$primaryKey->name`)";
        }
        $sql .= "CREATE TABLE IF NOT EXISTS `$this->sqlTableName`(\n";
        $sql .= implode(
            ",\n",
            array_map(function ($statement) {
                return "\t" . $statement;
            }, $statements)
        );
        $sql .= ")\nENGINE=InnoDB\nCOLLATE=" . $this->collation . ";\n\n";

        $addedColumns = [];

        $sql .= "ALTER TABLE `$this->sqlTableName`\n";
        foreach ($this->columns->getElements() as $column) {
            $columnSQL = $column->getSql(true);
            if ($columnSQL !== null) {
                $addedColumns[] = $columnSQL;
            }
        }
        foreach ($this->virtualColumns->getElements() as $virtualColumn) {
            $addedColumns[] = $virtualColumn->getSql(true);
        }

        $sql .= implode(
                ",\n",
                array_map(function ($addedColumn) {
                    return "\t" . $addedColumn;
                }, $addedColumns)
            ) . ";\n\n";

        $indexes = [];
        foreach ($this->indexes->getElements() as $index) {
            $sql .= $index->getSql($this->sqlTableName) . ";\n";
        }
        $sql .= "\n";

        foreach ($this->foreignKeys->getElements() as $foreignKey) {
            $sql .= "ALTER TABLE `$this->sqlTableName` " . $foreignKey->getSql($this->sqlTableName) . ";\n";
        }
        if ($this->foreignKeys->count()) {
            $sql .= "\n";
        }

        if (isset($this->triggers) && count($this->triggers->getElements())) {
            foreach ($this->triggers->getElements() as $trigger) {
                $sql .= $trigger->getSql($this->entityClassWithNamespace) . "\n\n";
            }
        }

        return $sql . "\n\n";
    }

    /**
     * Generates PHP Code for Doctrine model
     * @return string
     */
    public function getDoctrineModelCode(): string
    {
        $doctrineModelClass = DoctrineModel::class;
        $intro = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$this->modelClassWithNamespace->namespace};\n\n";

        if ($this->parentEntityCLassWithNamespace) {
            // in case of a subclass, we do not define table names etc., but instead the parent class
            $parentModelClassWithNamespace = self::getModelClassWithNamespaceForEntityClassWithNamespace(
                $this->parentEntityCLassWithNamespace
            );
            $modelClassContent = "#[ORM\Entity]\nclass {$this->getModelClassNameWithNameSpace()->name} extends $parentModelClassWithNamespace->name\n{\n\tpublic const string ENTITY_CLASS = '{$this->entityClassWithNamespace->getNameWithNamespace()}';\n\n";
        } else {
            $subclassIndicatorDeclarations = '';
            if ($this->subclassIndicator) {
                $singleClassIndicatorColumn = $this->columns->getColumnByName(
                    $this->subclassIndicator->indicatorPropertyName
                );
                $doctrineClassDiscriminatorMapPHPCode = $this->subclassIndicator->getDoctrineClassDiscriminatorMapPHPCode();
                $subclassIndicatorDeclarations = "\n#[ORM\InheritanceType('SINGLE_TABLE')]\n#[ORM\DiscriminatorColumn(name: '{$this->subclassIndicator->indicatorPropertyName}', type: '$singleClassIndicatorColumn->phpType')]\n$doctrineClassDiscriminatorMapPHPCode\n";

                // if one of the subclass indicator model classes has a different namespace than current model, we need to add it as import
                $modelImportsFromSubclassIndicators = $this->subclassIndicator->getDatabaseModelImportsBasedOnSubclassIndicatorsForDatabaseModel(
                    $this
                );
                $this->modelImports->mergeFromOtherSet($modelImportsFromSubclassIndicators);
            }
            $modelClassContent = "#[ORM\Entity]\n#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]\n#[ORM\Table(name: '$this->sqlTableName')]$subclassIndicatorDeclarations\nclass {$this->getModelClassNameWithNameSpace()->name} extends DoctrineModel\n{\n\tpublic const string MODEL_ALIAS = '$this->name';\n\n\tpublic const string TABLE_NAME = '$this->sqlTableName';\n\n\tpublic const string ENTITY_CLASS = '{$this->entityClassWithNamespace->getNameWithNamespace()}';\n\n";
        }

        if ($this->virtualColumns->count()) {
            $virtualColumns = '';
            foreach ($this->virtualColumns->getElements() as $virtualColumn) {
                $virtualColumnProperties = [
                    'createIndex' => $virtualColumn->createIndex,
                    'stored' => $virtualColumn->stored,
                    'as' => $virtualColumn->as,
                    'referenceColumn' => $virtualColumn->referenceColumn->name,
                    'referenceColumnStored' => !$virtualColumn->referenceColumn->ignoreProperty,
                ];

                $virtualColumnPropertiesString = var_export($virtualColumnProperties, true);

                $virtualColumnPropertiesString = preg_replace('/\barray\s*\(/', '[', $virtualColumnPropertiesString);
                $virtualColumnPropertiesString = preg_replace('/\)\s*$/', ']', $virtualColumnPropertiesString);

                $virtualColumnPropertiesString = preg_replace('/\s+/', ' ', $virtualColumnPropertiesString);
                $virtualColumnPropertiesString = str_replace(' ,', ',', $virtualColumnPropertiesString);
                $virtualColumnPropertiesString = trim($virtualColumnPropertiesString);

                $virtualColumns .= "'{$virtualColumn->getName()}' => $virtualColumnPropertiesString, ";
            }

            $virtualColumns = rtrim($virtualColumns, ', ');

            $modelClassContent .= "\t" . 'public static array $virtualColumns = [' . $virtualColumns . '];' . "\n\n";
        }
        $databaseColumnModelImport = new DatabaseModelImport(DatabaseColumn::getClassWithNamespace());
        $this->modelImports->add($databaseColumnModelImport);

        foreach ($this->columns->getElements() as $column) {
            // doctrine does not want the discriminator column (in our case the subclassIndicator to be part of the model definition
            /*
            if ($this->subclassIndicator && $this->subclassIndicator->indicatorPropertyName == $column->name) {
                continue;
            }*/
            // Ignore columns which are to be excluded
            if ($column->ignoreProperty) {
                continue;
            }
            if ($this->subclassIndicator && $this->subclassIndicator->indicatorPropertyName == $column->name) {
                $column->phpDefaultValue = $this->subclassIndicator->getDefaultValueOfIndicatorForEntityClass(
                    $this->entityClassWithNamespace->getNameWithNamespace()
                );
            }

            // regular properties
            if ($column->isPrimaryKey) {
                $modelClassContent .= "\t#[ORM\Id]\n";
            }
            $dataBasecolumnProperties = [];
            if ($column->isMergableJSONColumn) {
                $dataBasecolumnProperties[] = 'isMergableJSONColumn: true';
            }
            if ($column->onUpdateAction) {
                $quote = str_contains($column->onUpdateAction, "'") ? '"' : "'";
                $dataBasecolumnProperties[] = "onUpdateAction:$quote" . $column->onUpdateAction . $quote;
            }
            if ($dataBasecolumnProperties) {
                $modelClassContent .= "\t#[DatabaseColumn(" . implode(', ', $dataBasecolumnProperties) . ")]\n";
            }
            if ($column->hasAutoIncrement) {
                $modelClassContent .= "\t#[ORM\GeneratedValue]\n";
            }
            // For VECTOR columns we propagate the dimension via Doctrine's `length` argument so that
            // both VectorType::getSQLDeclaration and DoctrineEntityManager::upsert can read it from
            // ClassMetadata::$fieldMappings without reflection lookups at runtime.
            $columnAttributeArgs = ["type: '{$column->getDoctrineColumnAttributeType()}'"];
            if ($column->sqlType === DatabaseColumn::SQL_TYPE_VECTOR && ($column->vectorDimensions ?? 0) > 0) {
                $columnAttributeArgs[] = 'length: ' . $column->vectorDimensions;
            }
            $modelClassContent .= "\t#[ORM\Column(" . implode(', ', $columnAttributeArgs) . ")]\n";
            // avoid Type mixed cannot be marked as nullable since mixed already includes null
            try {
                $isNullable = $column->allowsNull && $column->getDoctrinePhpType() != 'mixed';
            } catch (InternalErrorException $e) {
                throw new InternalErrorException(
                    "Could not determine Doctrine PHP type for column $column->name in Model {$this->modelClassWithNamespace->getNameWithNamespace()} "
                );
            }
            $modelClassContent .= "\tpublic " . ($isNullable ? '?' : '') . $column->getDoctrinePhpType(
                ) . ' $' . $column->name . (isset($column->phpDefaultValue) ? ' = ' . $column->getPhpDefaultValueAsString() : '') . ";\n";
            $modelClassContent .= "\n";
        }

        foreach ($this->virtualColumns->getElements() as $virtualColumn) {
            $modelClassContent .= "\t#[ORM\Column(type: '{$virtualColumn->referenceColumn->getDoctrineColumnAttributeType()}')]\n";
            // avoid Type mixed cannot be marked as nullable since mixed already includes null
            $modelClassContent .= "\tpublic " . $virtualColumn->referenceColumn->getDoctrinePhpType() . ' $' . $virtualColumn->getName(
                ) . (isset($virtualColumn->referenceColumn->phpDefaultValue) ? ' = ' . $virtualColumn->referenceColumn->getPhpDefaultValueAsString(
                    ) : '') . ";\n";
            $modelClassContent .= "\n";
        }

        // belongs to
        // Belongs-to / ManyToOne Relationsships (flipped to OneToOne when this FK is the owning side of a 1:1)
        foreach ($this->foreignKeys->getElements() as $foreignKey) {
            $inversedByPropertyName = $this->resolveOneToOneOwningInversedBy($foreignKey);
            if ($inversedByPropertyName !== null) {
                $modelClassContent .= "\t#[ORM\OneToOne(targetEntity: $foreignKey->foreignModelClassName::class, inversedBy: '$inversedByPropertyName')]\n";
            } else {
                $modelClassContent .= "\t#[ORM\ManyToOne(targetEntity: $foreignKey->foreignModelClassName::class)]\n";
            }
            $modelClassContent .= "\t#[ORM\JoinColumn(name: '$foreignKey->internalIdColumn', referencedColumnName: '$foreignKey->foreignIdColumn')]\n";
            $modelClassContent .= "\tpublic " . ($this->columns->getColumnByName(
                    $foreignKey->internalIdColumn
                )->allowsNull ? '?' : '') . "$foreignKey->foreignModelClassName $" . "$foreignKey->internalColumn;\n\n";
        }
        // one to many relationships
        foreach ($this->getOneToManyRelationsShips()->getElements() as $oneToManyRelationship) {
            $modelClassContent .= "\t#[ORM\OneToMany(targetEntity: $oneToManyRelationship->targetModelName::class, mappedBy: '$oneToManyRelationship->mappedByPropertyName')]\n";
            $modelClassContent .= "\tpublic PersistentCollection $" . $oneToManyRelationship->propertyName . ";\n\n";
        }
        // one to one inverse relationships (the single reference back on the parent; FK lives on the target)
        foreach ($this->getOneToOneInverseRelationships()->getElements() as $oneToOneInverseRelationship) {
            $modelClassContent .= "\t#[ORM\OneToOne(targetEntity: $oneToOneInverseRelationship->targetModelName::class, mappedBy: '$oneToOneInverseRelationship->mappedByPropertyName')]\n";
            $modelClassContent .= "\tpublic ?$oneToOneInverseRelationship->targetModelName $" . $oneToOneInverseRelationship->propertyName . ";\n\n";
        }
        // imports need to be generated after getOneToManyRelationShips, as within the generation of oneToManyRelationShips
        // additional imports of Models from foreign namespaces can be added
        $imports = "use $doctrineModelClass;\nuse Doctrine\ORM\Mapping as ORM;\nuse Doctrine\ORM\PersistentCollection;\nuse DateTime;\n";
        foreach ($this->modelImports->getElements() as $modelImport) {
            $imports .= $modelImport->getImportDefinition() . "\n";
        }
        $imports .= "\n";

        $modelClassContent = $intro . $imports . $modelClassContent . '}';
        return $modelClassContent;
    }

    public function getOneToManyRelationsShips(): DatabaseOneToManyRelationships
    {
        if (isset($this->oneToManyRelationships)) {
            return $this->oneToManyRelationships;
        }
        $this->oneToManyRelationships = new DatabaseOneToManyRelationships();
        $databaseModels = EntityModelGeneratorService::getDatabaseModels();
        foreach ($this->potentialOneToManyRelationships as $reflectionProperty) {
            /** @var EntitySet $entitySetClass */
            $entitySetClass = $reflectionProperty->getType()->getName();
            $entityClass = $entitySetClass::getEntityClass();
            if (!$entityClass) {
                continue;
            }
            $targetModel = $databaseModels->getModelByEntityClass($entityClass);
            if (!$targetModel) {
                continue;
            }
            $foreignKeyInTargetClass = $targetModel->foreignKeys->getDatabaseForeignKeyByForeignModelName(
                $this->getModelClassNameWithNameSpace()->name
            );
            if (!$foreignKeyInTargetClass) {
                continue;
            }
            // if current namespace differs from foreign class, we need to add it as import
            if (
                $this->modelClassWithNamespace->namespace != $targetModel->getModelClassNameWithNameSpace()->namespace
            ) {
                $modelImport = new DatabaseModelImport(
                    $targetModel->getModelClassNameWithNameSpace(), $this->modelClassWithNamespace->namespace
                );
                $this->modelImports->add($modelImport);
            }
            $databaseOneToManyRelationShip = new DatabaseOneToManyRelationship(
                $reflectionProperty->getName(), $targetModel->getModelClassNameWithNameSpace()->name, $foreignKeyInTargetClass->internalColumn
            );
            $this->oneToManyRelationships->add($databaseOneToManyRelationShip);
        }
        return $this->oneToManyRelationships;
    }

    public function getOneToOneInverseRelationships(): DatabaseOneToOneInverseRelationships
    {
        if (isset($this->oneToOneInverseRelationships)) {
            return $this->oneToOneInverseRelationships;
        }
        $this->oneToOneInverseRelationships = new DatabaseOneToOneInverseRelationships();
        $databaseModels = EntityModelGeneratorService::getDatabaseModels();
        $thisModelName = $this->getModelClassNameWithNameSpace()->name;
        foreach ($this->potentialOneToOneInverseRelationships as $reflectionProperty) {
            /** @var Entity $targetEntityClass */
            $targetEntityClass = $reflectionProperty->getType()->getName();
            $targetModel = $databaseModels->getModelByEntityClass((string)$targetEntityClass);
            if (!$targetModel) {
                continue;
            }
            // Pick the target FK back to us whose id-column is UNIQUE (the 1:1 FK), NOT the addAsParent 1:N FK.
            $owningPropertyName = null;
            foreach ($targetModel->foreignKeys->getElements() as $candidateForeignKey) {
                if ($candidateForeignKey->foreignModelClassName !== $thisModelName) {
                    continue;
                }
                if (self::columnHasUniqueIndex($targetModel->indexes, $candidateForeignKey->internalIdColumn)) {
                    $owningPropertyName = $candidateForeignKey->internalColumn;
                    break;
                }
            }
            if ($owningPropertyName === null) {
                continue;
            }
            // if current namespace differs from target class, we need to add it as import
            if (
                $this->modelClassWithNamespace->namespace != $targetModel->getModelClassNameWithNameSpace()->namespace
            ) {
                $modelImport = new DatabaseModelImport(
                    $targetModel->getModelClassNameWithNameSpace(), $this->modelClassWithNamespace->namespace
                );
                $this->modelImports->add($modelImport);
            }
            $databaseOneToOneInverseRelationship = new DatabaseOneToOneInverseRelationship(
                $reflectionProperty->getName(),
                $targetModel->getModelClassNameWithNameSpace()->name,
                $owningPropertyName
            );
            $this->oneToOneInverseRelationships->add($databaseOneToOneInverseRelationship);
        }
        return $this->oneToOneInverseRelationships;
    }

    /**
     * Returns the inverse property name to use as Doctrine `inversedBy` when this $foreignKey is the OWNING side of
     * a bidirectional 1:1, or null when it is a plain ManyToOne. The owning side qualifies only when its own
     * id-column is UNIQUE AND the foreign model declares a matching {@see getOneToOneInverseRelationships()} entry
     * (target == this model, mappedBy == this FK's property). Keeps the ManyToOne/OneToOne flip symmetric across
     * the two generated models.
     */
    protected function resolveOneToOneOwningInversedBy(DatabaseForeignKey $foreignKey): ?string
    {
        if (!self::columnHasUniqueIndex($this->indexes, $foreignKey->internalIdColumn)) {
            return null;
        }
        $thisModelName = $this->getModelClassNameWithNameSpace()->name;
        foreach (EntityModelGeneratorService::getDatabaseModels()->getElements() as $candidateModel) {
            if ($candidateModel->getModelClassNameWithNameSpace()->name !== $foreignKey->foreignModelClassName) {
                continue;
            }
            foreach ($candidateModel->getOneToOneInverseRelationships()->getElements() as $inverseRelationship) {
                if ($inverseRelationship->targetModelName === $thisModelName
                    && $inverseRelationship->mappedByPropertyName === $foreignKey->internalColumn) {
                    return $inverseRelationship->propertyName;
                }
            }
        }
        return null;
    }

    /**
     * Whether $columnName has a single-column UNIQUE index in $indexes. Scans columns + type directly because
     * {@see DatabaseIndexes::getIndexForSingleColumnName()} looks up by a key WITHOUT the index-type suffix while
     * {@see DatabaseIndex::uniqueKey()} bakes the type into the key, so that helper never matches a typed index.
     */
    protected static function columnHasUniqueIndex(DatabaseIndexes $indexes, string $columnName): bool
    {
        foreach ($indexes->getElements() as $index) {
            if ($index->indexType === DatabaseIndex::TYPE_UNIQUE
                && count($index->indexColumns) === 1
                && $index->indexColumns[0] === $columnName) {
                return true;
            }
        }
        return false;
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKey($this->modelClassWithNamespace->getNameWithNamespace());
    }
}

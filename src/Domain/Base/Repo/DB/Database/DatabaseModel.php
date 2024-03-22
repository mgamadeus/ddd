<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Attributes\DatabaseTranslation;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\Mapping\Table;
use ReflectionException;
use ReflectionNamedType;

class DatabaseModel extends ValueObject
{
    public const DEFAULT_COLLATION = 'utf8mb4_unicode_ci';
    public const MODEL_SUFFIX = 'Model';

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

    /** @var DatabaseTriggers|null Database Triggers */
    public ?DatabaseTriggers $triggers;

    /** @var DatabaseModelImports|null Database model imports (used for Doctrine model generation) */
    public ?DatabaseModelImports $modelImports;

    /** @var string The Table's collation */
    public string $collation = self::DEFAULT_COLLATION;

    /** @var ReflectionProperty[] */
    public $potentialOneToManyRelationships = [];

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

        $databaseModel->sqlTableName = Config::getEnv(
                'DATABASE_TABLE_PREFIX'
            ) . $databaseModel->entitySetClassWithNamespace->name;
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
                if ($subclassIndicatorAttibute = $parentClassReflectionProperty->getAttributes(
                    SubclassIndicator::class
                )[0] ?? null) {
                    $reflectionProperties[] = $parentClassReflectionProperty;
                    /** @var SubclassIndicator $subclassIndicatorAttibuteInstance */
                    $subclassIndicatorAttibuteInstance = $subclassIndicatorAttibute->newInstance();
                    $subclassIndicatorAttibuteInstance->indicatorPropertyName = $parentClassReflectionProperty->getName(
                    );
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
                if ($subclassIndicatorAttibute = $reflectionProperty->getAttributes(
                    SubclassIndicator::class
                )[0] ?? null) {
                    /** @var SubclassIndicator $subclassIndicatorAttibuteInstance */
                    $subclassIndicatorAttibuteInstance = $subclassIndicatorAttibute->newInstance();
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

        foreach ($reflectionProperties as $reflectionProperty) {
            // create regular columns
            $databaseColumn = DatabaseColumn::createFromReflectionProperty($entityReflectionClass, $reflectionProperty);
            if ($databaseColumn) {
                $databaseModel->columns->add($databaseColumn);
                if ($virtualColumnAttributes = $reflectionProperty->getAttributes(DatabaseVirtualColumn::class)) {
                    foreach ($virtualColumnAttributes as $virtualColumnAttribute) {
                        /** @var DatabaseVirtualColumn $virtualColumnAttributeInstance */
                        $virtualColumnAttributeInstance = $virtualColumnAttribute->newInstance();
                        $virtualColumnAttributeInstance->referenceColumn = $databaseColumn;
                        $databaseModel->virtualColumns->add($virtualColumnAttributeInstance);
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

            if ($databaseColumn && ($databaseColumn?->sqlType ?? null) != DatabaseColumn::SQL_TYPE_JSON && !$databaseColumn->isPrimaryKey) {
                // handle indexes
                $indexAttributes = $reflectionProperty->getAttributes(DatabaseIndex::class);
                if (count($indexAttributes)) {
                    foreach ($indexAttributes as $indexAttribute) {
                        /** @var DatabaseIndex $indexAttributeInstance */
                        $indexAttributeInstance = $indexAttribute->newInstance();
                        if ($indexAttributeInstance->indexType != DatabaseIndex::TYPE_NONE) {
                            $indexAttributeInstance->indexColumns = [$reflectionProperty->getName()];
                            $databaseModel->indexes->add($indexAttributeInstance);
                        }
                    }
                } elseif ($databaseColumn) {
                    $index = new DatabaseIndex(indexColumns: [$databaseColumn->name]);
                    $databaseModel->indexes->add($index);
                }
            }

            // handle indexes added to potentialOneToManyPropertyNames and processed later
            // in order to avoid recursion, we need to process first all Classes and then go through them and
            // add one to many relationsships later
            if ($reflectionProperty->getType() instanceof ReflectionNamedType && is_a(
                    $reflectionProperty->getType()->getName(),
                    EntitySet::class,
                    true
                ) && $lazyLoadAttributes = $reflectionProperty->getAttributes(LazyLoad::class)) {
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
            if ($reflectionProperty->getType() instanceof ReflectionNamedType && is_a(
                    $reflectionProperty->getType()->getName(),
                    Entity::class,
                    true
                )) {
                $propertyLazyLoadAttributes = $reflectionProperty->getAttributes(LazyLoad::class);
                $propertyDBRepoLazyloadAttribute = null;
                foreach ($propertyLazyLoadAttributes as $propertyLazyLoadAttribute) {
                    /** @var LazyLoad $propertyLazyLoadAttributeInstance */
                    $propertyLazyLoadAttributeInstance = $propertyLazyLoadAttribute->newInstance();
                    if ($propertyLazyLoadAttributeInstance->repoType == LazyLoadRepo::DB) {
                        $propertyDBRepoLazyloadAttribute = $propertyLazyLoadAttributeInstance;
                        break;
                    }
                }
                /** @var Entity $foreignClassName */
                $foreignClassName = $reflectionProperty->getType()->getName();
                $foreignClassReflectionClass = ReflectionClass::instance((string)$foreignClassName);
                $hasDBRelatedRepo = false;
                foreach ($foreignClassReflectionClass->getAttributes(LazyLoadRepo::class) as $repoAttribute) {
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
                    if (($legacyDBEntity = $foreignClassName::getRepoClass(LazyLoadRepo::LEGACY_DB))
                        && !($propertyDBRepoLazyloadAttribute && $foreignClassName::getRepoClass(LazyLoadRepo::DB))
                    ) {
                        /** @var DatabaseRepoEntity $legacyDBEntity */
                        $foreignModelReflectionClass = ReflectionClass::instance($legacyDBEntity::BASE_ORM_MODEL);
                        $foreignModelClassWithNamespace = $foreignModelReflectionClass->getClassWithNamespace();
                        $foreignModelClassName = $foreignModelClassWithNamespace->name;
                        $modelImport = new DatabaseModelImport(
                            $foreignModelClassWithNamespace,
                            $databaseModel->modelClassWithNamespace->namespace
                        );
                        $databaseModel->modelImports->add($modelImport);
                        $tableAttribute = $foreignModelReflectionClass->getAttributes(Table::class)[0] ?? null;
                        if (!$tableAttribute) {
                            throw new InternalErrorException(
                                "Model {$foreignModelClassName} has no ORM\Table attribute"
                            );
                        }
                        /** @var Table $tableAttributeInstance */
                        $tableAttributeInstance = $tableAttribute->newInstance();
                        $foreignTableName = $tableAttributeInstance->name;
                    } else {
                        $foreignModelClassWithNamespace = self::getModelClassWithNamespaceForEntityClassWithNamespace(
                            $foreignClassWithNamespace
                        );
                        $foreignModelClassName = $foreignModelClassWithNamespace->name;
                        $foreignTableName = Config::getEnv(
                                'DATABASE_TABLE_PREFIX'
                            ) . $foreignEntitySetClassWithNamespace->name;

                        // foreign model is from different namespace, we need to add an import
                        if ($databaseModel->modelClassWithNamespace->namespace != $foreignModelClassWithNamespace->namespace) {
                            $modelImport = new DatabaseModelImport(
                                $foreignModelClassWithNamespace,
                                $databaseModel->modelClassWithNamespace->namespace
                            );
                            $databaseModel->modelImports->add($modelImport);
                        }
                    }

                    $internalColumnProperty = $entityReflectionClass->getProperty($internalColumn);
                    $foreignKey = null;

                    // if we find an DatabaseForeignKey attribute, we use it
                    if ($foreignReferenceAttribute = $reflectionProperty->getAttributes(
                        DatabaseForeignKey::class
                    )[0] ?? null) {
                        /** @var DatabaseForeignKey $foreignKeyAttributeInstance */
                        $foreignKeyAttributeInstance = $foreignReferenceAttribute->newInstance();
                        $foreignKey = $foreignKeyAttributeInstance;
                    } else {
                        $foreignKey = new DatabaseForeignKey();
                    }
                    if ($foreignKey->onUpdateAction == $foreignKey::ACTION_SET_NULL || $foreignKey->onDeleteAction == $foreignKey::ACTION_SET_NULL
                        && !$internalColumnProperty->getType()->allowsNull()
                    ) {
                        throw new InternalErrorException(
                            "{$entityClassName}.{$internalColumn} does not allow null, 
                        but foreign reference definitions applied by attribute on {$entityClassName}.{$reflectionProperty->getName()} define SET NULL on DELTE or UPDATE"
                        );
                    }
                    $foreignKey->internalColumn = $reflectionProperty->getName();
                    $foreignKey->internalIdColumn = $internalColumn;
                    $foreignKey->foreignTable = $foreignTableName;
                    $foreignKey->foreignModelClassName = $foreignModelClassName;
                    $databaseModel->foreignKeys->add($foreignKey);
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
        foreach ($entityReflectionClass->getAttributes(DatabaseIndex::class) as $indexAttribute) {
            /** @var DatabaseIndex $indexAttributeInstance */
            $indexAttributeInstance = $indexAttribute->newInstance();
            $databaseModel->indexes->add($indexAttributeInstance);
        }

        // handle triggers
        foreach ($entityReflectionClass->getAttributes(DatabaseTrigger::class) as $triggerAttribute) {
            /** @var DatabaseTrigger $triggerAttributeInstance */
            $triggerAttributeInstance = $triggerAttribute->newInstance();
            $databaseModel->triggers->add($triggerAttributeInstance);
        }
        return $databaseModel;
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

        $sql = "#################### {$this->sqlTableName} ####################\n";
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
        $this->columns->elements = $databaseColumns;
        foreach ($this->columns->getElements() as $column) {
            $statements[] = $column->getSql();
        }
        if ($primaryKey = $this->columns->getPrimaryKeyColumn()) {
            $statements[] = "PRIMARY KEY (`{$primaryKey->name}`)";
        }
        $sql .= "CREATE TABLE IF NOT EXISTS `{$this->sqlTableName}`(\n";
        $sql .= implode(
            ",\n",
            array_map(function ($statement) {
                return "\t" . $statement;
            }, $statements)
        );
        $sql .= ")\nENGINE=InnoDB\nCOLLATE=" . $this->collation . ";\n\n";

        $addedColumns = [];

        $sql .= "ALTER TABLE `{$this->sqlTableName}`\n";
        foreach ($this->columns->getElements() as $column) {
            $addedColumns[] = $column->getSql(true);
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
            $sql .= "ALTER TABLE `{$this->sqlTableName}` " . $foreignKey->getSql($this->sqlTableName) . ";\n";
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
        $namespace = str_replace('Entities', 'Repo\\DB', $entityClassWithNamespace->namespace);
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
            $modelClassContent = "#[ORM\Entity]\nclass {$this->getModelClassNameWithNameSpace()->name} extends {$parentModelClassWithNamespace->name}\n{\n\tpublic const ENTITY_CLASS = '{$this->entityClassWithNamespace->getNameWithNamespace()}';\n\n";
        } else {
            $subclassIndicatorDeclarations = '';
            if ($this->subclassIndicator) {
                $singleClassIndicatorColumn = $this->columns->getColumnByName(
                    $this->subclassIndicator->indicatorPropertyName
                );
                $doctrineClassDiscriminatorMapPHPCode = $this->subclassIndicator->getDoctrineClassDiscriminatorMapPHPCode(
                );
                $subclassIndicatorDeclarations = "\n#[ORM\InheritanceType('SINGLE_TABLE')]\n#[ORM\DiscriminatorColumn(name: '{$this->subclassIndicator->indicatorPropertyName}', type: '{$singleClassIndicatorColumn->phpType}')]\n{$doctrineClassDiscriminatorMapPHPCode}\n";

                // if one of the subclass indicator model classes has a different namespace than current model, we need to add it as import
                $modelImportsFromSubclassIndicators = $this->subclassIndicator->getDatabaseModelImportsBasedOnSubclassIndicatorsForDatabaseModel(
                    $this
                );
                $this->modelImports->mergeFromOtherSet($modelImportsFromSubclassIndicators);
            }
            $modelClassContent = "#[ORM\Entity]\n#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]\n#[ORM\Table(name: '{$this->sqlTableName}')]{$subclassIndicatorDeclarations}\nclass {$this->getModelClassNameWithNameSpace()->name} extends DoctrineModel\n{\n\tpublic const MODEL_ALIAS = '{$this->name}';\n\n\tpublic const TABLE_NAME = '{$this->sqlTableName}';\n\n\tpublic const ENTITY_CLASS = '{$this->entityClassWithNamespace->getNameWithNamespace()}';\n\n";
        }
        $jsonMergableColumns = [];
        foreach ($this->columns->getElements() as $column) {
            if ($column->isMergableJSONColumn){
                $jsonMergableColumns[$column->name] = true;
            }
        }
        if (!empty($jsonMergableColumns)) {
            $jsonMergableColumnsContent = implode(', ', array_map(function ($key) {
                return "'{$key}' => true";
            }, array_keys($jsonMergableColumns)));
            $modelClassContent .= "\t" . 'public array $jsonMergableColumns = [' . $jsonMergableColumnsContent . '];' . "\n\n";
        }

        foreach ($this->columns->getElements() as $column) {
            // doctrine does not want the discriminator column (in our case the subclassIndicator to be part of the model definition
            /*
            if ($this->subclassIndicator && $this->subclassIndicator->indicatorPropertyName == $column->name) {
                continue;
            }*/
            if ($this->subclassIndicator && $this->subclassIndicator->indicatorPropertyName == $column->name) {
                $column->phpDefaultValue = $this->subclassIndicator->getDefaultValueOfIndicatorForEntityClass(
                    $this->entityClassWithNamespace->getNameWithNamespace()
                );
            }

            // regular properties
            if ($column->isPrimaryKey) {
                $modelClassContent .= "\t#[ORM\Id]\n";
            }
            if ($column->hasAutoIncrement) {
                $modelClassContent .= "\t#[ORM\GeneratedValue]\n";
            }
            $modelClassContent .= "\t#[ORM\Column(type: '{$column->getDoctrineColumnAttributeType()}')]\n";
            // avoid Type mixed cannot be marked as nullable since mixed already includes null
            $isNullable = $column->allowsNull && $column->getDoctrinePhpType() != 'mixed';
            $modelClassContent .= "\tpublic " . ($isNullable ? '?' : '') . $column->getDoctrinePhpType(
                ) . ' $' . $column->name . (isset($column->phpDefaultValue) ? ' = ' . $column->getPhpDefaultValueAsString(
                    ) : '') . ";\n";
            $modelClassContent .= "\n";
        }

        foreach ($this->virtualColumns->getElements() as $virtualColumn) {
            $modelClassContent .= "\t#[ORM\Column(type: '{$virtualColumn->referenceColumn->getDoctrineColumnAttributeType()}')]\n";
            // avoid Type mixed cannot be marked as nullable since mixed already includes null
            $modelClassContent .= "\tpublic " . $virtualColumn->referenceColumn->getDoctrinePhpType(
                ) . ' $' . $virtualColumn->getName(
                ) . (isset($virtualColumn->referenceColumn->phpDefaultValue) ? ' = ' . $virtualColumn->referenceColumn->getPhpDefaultValueAsString(
                    ) : '') . ";\n";
            $modelClassContent .= "\n";
        }


        // belongs to
        // Belongs-to / ManyToOne Relationsships
        foreach ($this->foreignKeys->getElements() as $foreignKey) {
            $modelClassContent .= "\t#[ORM\ManyToOne(targetEntity: {$foreignKey->foreignModelClassName}::class)]\n";
            $modelClassContent .= "\t#[ORM\JoinColumn(name: '{$foreignKey->internalIdColumn}', referencedColumnName: '{$foreignKey->foreignIdColumn}')]\n";
            $modelClassContent .= "\tpublic " . ($this->columns->getColumnByName(
                    $foreignKey->internalIdColumn
                )->allowsNull ? '?' : '') . "{$foreignKey->foreignModelClassName} $" . "{$foreignKey->internalColumn};\n\n";
        }
        // one to many relationships
        foreach ($this->getOneToManyRelationsShips()->getElements() as $oneToManyRelationship) {
            $modelClassContent .= "\t#[ORM\OneToMany(targetEntity: {$oneToManyRelationship->targetModelName}::class, mappedBy: '{$oneToManyRelationship->mappedByPropertyName}')]\n";
            $modelClassContent .= "\tpublic PersistentCollection $" . $oneToManyRelationship->propertyName . ";\n\n";
        }
        // imports need to be generated after getOneToManyRelationShips, as within the generation of oneToManyRelationShips
        // additional imports of Models from foreign namespaces can be added
        $imports = "use {$doctrineModelClass};\nuse Doctrine\ORM\Mapping as ORM;\nuse Doctrine\ORM\PersistentCollection;\nuse DateTime;\n";
        foreach ($this->modelImports->getElements() as $modelImport) {
            $imports .= $modelImport->getImportDefinition() . "\n";
        }
        $imports .= "\n";

        $modelClassContent = $intro . $imports . $modelClassContent . '}';
        return $modelClassContent;
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKey($this->modelClassWithNamespace->getNameWithNamespace());
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
            if ($this->modelClassWithNamespace->namespace != $targetModel->getModelClassNameWithNameSpace(
                )->namespace) {
                $modelImport = new DatabaseModelImport(
                    $targetModel->getModelClassNameWithNameSpace(),
                    $this->modelClassWithNamespace->namespace
                );
                $this->modelImports->add($modelImport);
            }
            $databaseOneToManyRelationShip = new DatabaseOneToManyRelationship(
                $reflectionProperty->getName(),
                $targetModel->getModelClassNameWithNameSpace()->name,
                $foreignKeyInTargetClass->internalColumn
            );
            $this->oneToManyRelationships->add($databaseOneToManyRelationShip);
        }
        return $this->oneToManyRelationships;
    }
}
<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;

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
#[Attribute(Attribute::TARGET_PROPERTY)]
class SubclassIndicator extends ValueObject
{
    use BaseAttributeTrait;

    public array $indicators = [];

    /** @var string|null The property name that indicates the class name */
    public ?string $indicatorPropertyName = null;

    /**
     * @return ClassWithNamespace[]
     */
    public function getSubClasses(): array
    {
        $classesWithNameSpaces = [];
        foreach ($this->indicators as $propertyValue => $className) {
            $classesWithNameSpaces[] = new ClassWithNamespace($className);
        }
        return $classesWithNameSpaces;
    }

    /**
     * Returns the value that is associated for given entity Class
     * @param string $entityClass
     * @return string|null
     */
    public function getDefaultValueOfIndicatorForEntityClass(string $entityClass): ?string
    {
        foreach ($this->indicators as $value => $defaultEntityClass) {
            if ($entityClass == $defaultEntityClass) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return string Returns the Doctrine DiscriminatorMap class attribute for the generation of the Doctrine Model
     * PHP Code e.g. #[DiscriminatorMap(['post' => 'Post', 'event' => 'Event', 'offer' => 'Offer'])]
     */
    public function getDoctrineClassDiscriminatorMapPHPCode(): string
    {
        $discriminatorMap = array_map(function ($propertyValue, $entityClassName) {
            $entityReflectionClass = ReflectionClass::instance($entityClassName);
            $entityClassWithNamespace = $entityReflectionClass->getClassWithNamespace();
            $entityClassWithNamespace = new ClassWithNamespace($entityClassName);
            $entityModelClassWithNamespace = DatabaseModel::getModelClassWithNamespaceForEntityClassWithNamespace($entityClassWithNamespace);
            return "'$propertyValue' => {$entityModelClassWithNamespace->name}::class";
        }, array_keys($this->indicators), $this->indicators);
        $discriminatorMapString = implode(', ', $discriminatorMap);
        $doctrineModelString = "#[ORM\DiscriminatorMap([$discriminatorMapString])]";
        return $doctrineModelString;
    }

    /**
     * Returns DatabaseModelImports for Entity derived DatabaseModels that are stated as SubClassIndicator with namespace
     * different from fiven DatabaseModel's namespace
     * @param DatabaseModel $databaseModel
     * @return DatabaseModelImports
     * @throws \ReflectionException
     */
    public function getDatabaseModelImportsBasedOnSubclassIndicatorsForDatabaseModel(DatabaseModel $databaseModel):DatabaseModelImports {
        $databaseModelImports = new DatabaseModelImports();
        foreach ($this->indicators as $propertyValue => $subclassIndicatorEntityClassName) {
            $subclassIndicatorEntityReflectionClass = ReflectionClass::instance(
                $subclassIndicatorEntityClassName
            );
            $subclassIndicatorEntityClassWithNamespace = $subclassIndicatorEntityReflectionClass->getClassWithNamespace(
            );
            $subClassIndicatorModelClassWithNamespace = DatabaseModel::getModelClassWithNamespaceForEntityClassWithNamespace(
                $subclassIndicatorEntityClassWithNamespace
            );
            if ($subClassIndicatorModelClassWithNamespace->namespace != $databaseModel->modelClassWithNamespace->namespace) {
                $modelImport = new DatabaseModelImport(
                    $subClassIndicatorModelClassWithNamespace,
                    $databaseModel->modelClassWithNamespace->namespace
                );
                $databaseModelImports->add($modelImport);
            }
        }
        return $databaseModelImports;
    }

    /**
     * an array of value => className
     * e.g. ['POST' => Post::class,'EVENT' => Event::class, ...]
     * @param array $indicators
     */
    public function __construct(
        array $indicators
    ) {
        $this->indicators = $indicators;
        parent::__construct();
    }
}
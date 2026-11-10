<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModelImport;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModelImports;
use DDD\Infrastructure\Reflection\ClassWithNamespace;

/** A database model of a given class with given imports, to ask how it writes a relation's target model */
class RelationTargetReferenceProbeDatabaseModel extends DatabaseModel
{
    /**
     * @param string $modelClassName The generated model class
     * @param string[] $importedClassNames Classes the model already imports (its foreign keys)
     */
    public static function fromModelClassAndImports(string $modelClassName, array $importedClassNames): static
    {
        $databaseModel = new static();
        $databaseModel->modelClassWithNamespace = new ClassWithNamespace($modelClassName);
        $databaseModel->modelImports = new DatabaseModelImports();
        foreach ($importedClassNames as $importedClassName) {
            $modelImport = new DatabaseModelImport(new ClassWithNamespace($importedClassName));
            $databaseModel->modelImports->add($modelImport);
        }
        return $databaseModel;
    }

    public function getReferenceNameOfRelationTargetModel(string $targetModelClassName): string
    {
        return $this->importRelationTargetModelAndGetReferenceName(new ClassWithNamespace($targetModelClassName));
    }

    /**
     * @return string[] The `use` lines of the model's imports
     */
    public function getImportDefinitions(): array
    {
        $importDefinitions = [];
        foreach ($this->modelImports->getElements() as $modelImport) {
            $importDefinitions[] = (string)$modelImport->getImportDefinition();
        }
        return $importDefinitions;
    }
}

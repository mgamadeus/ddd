<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property DatabaseModel[] $elements;
 * @method DatabaseModel getByUniqueKey(string $uniqueKey)
 * @method DatabaseModel first()
 * @method DatabaseModel[] getElements()
 */
class DatabaseModels extends ObjectSet
{
    /** @var DatabaseModel[] */
    private array $modelsByEntityClass = [];

    public function getSql(): string
    {
        $sql = '';
        foreach ($this->getElements() as $databaseModel) {
            $sql .= $databaseModel->getSql();
        }
        return $sql;
    }


    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            /** @var DatabaseModel $element */
            $this->modelsByEntityClass[$element->entityClassWithNamespace->getNameWithNamespace()] = $element;
        }
        parent::add(...$elements);
    }

    public function getModelByModelClassName(string $modelClassName): ?DatabaseModel
    {
        return $this->getByUniqueKey(DatabaseModel::uniqueKeyStatic($modelClassName));
    }

    public function getModelByEntityClass(string $entityClass): ?DatabaseModel
    {
        return $this->modelsByEntityClass[$entityClass] ?? null;
    }
}
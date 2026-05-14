<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * @method DatabaseModel getParent()
 * @property DatabaseForeignKey[] $elements;
 * @method DatabaseForeignKey getByUniqueKey(string $uniqueKey)
 * @method DatabaseForeignKey first()
 * @method DatabaseForeignKey[] getElements()
 */
class DatabaseForeignKeys extends ObjectSet
{
    /** @var DatabaseForeignKey[][] */
    protected array $foreignKeysByForeignModelClassName = [];

    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            /** @var DatabaseForeignKey $element */
            if (!isset($this->foreignKeysByForeignModelClassName[$element->foreignModelClassName])) {
                $this->foreignKeysByForeignModelClassName[$element->foreignModelClassName] = [];
            }
            $this->foreignKeysByForeignModelClassName[$element->foreignModelClassName][] = $element;
        }
        parent::add(...$elements);
    }

    public function getDatabaseForeignKeyByForeignModelName(string $foreignModelName): ?DatabaseForeignKey
    {
        $foreignKeys = $this->foreignKeysByForeignModelClassName[$foreignModelName] ?? null;
        if (!$foreignKeys) {
            return null;
        }
        if (count($foreignKeys) === 1) {
            return $foreignKeys[0];
        }
        // If multiple FKs point to the same target model, prefer the one whose property
        // declares #[LazyLoad(addAsParent: true)] — this is the parent-side relation that
        // the inverse OneToMany on the parent should mapBy. Example: Ingredient.components
        // (OneToMany) -> IngredientComponent has both parentIngredient (addAsParent: true)
        // and componentIngredient (plain). We want components to mapBy parentIngredient so
        // the JOIN uses parentIngredientId. Exactly one parent-relation FK per target is
        // supported; zero or more than one throws.
        $foreignKeysRepresentingParentRelation = [];
        $internalIdColumns = [];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->representsParentRelation ?? false) {
                $foreignKeysRepresentingParentRelation[] = $foreignKey;
            }
            $internalIdColumns[] = $foreignKey->internalIdColumn;
        }
        if (count($foreignKeysRepresentingParentRelation) === 1) {
            return $foreignKeysRepresentingParentRelation[0];
        }
        if (count($foreignKeysRepresentingParentRelation) > 1) {
            throw new InternalErrorException(
                'Multiple foreign keys referencing ' . $foreignModelName
                . ' (' . implode(',', $internalIdColumns) . ') are marked #[LazyLoad(addAsParent: true)]. '
                . 'Exactly one parent-relation per target type is supported per entity.'
            );
        }
        throw new InternalErrorException(
            'More than one foreign key referencing ' . $foreignModelName
            . ' (' . implode(',', $internalIdColumns) . ') and none is marked '
            . '#[LazyLoad(addAsParent: true)]. Mark exactly one of the matching properties '
            . 'with addAsParent: true to identify the parent-side relation.'
        );
    }

}
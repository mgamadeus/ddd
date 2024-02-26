<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;

class BasicEntityPersister extends \Doctrine\ORM\Persisters\Entity\BasicEntityPersister
{
    /**
     * Overwrites Doctrine BasicEntityPersister in order to fix duplication of columns
     * in case of multiple ManyToOne relationships that have the same joinColumn e.g.
     *
     * #[ORM\ManyToOne(targetEntity: LegacyDBCategoryModel::class)]
     * #[ORM\JoinColumn(name: '`item_id`', referencedColumnName: 'id')]
     * public ?LegacyDBCategoryModel $category;

     * #[ORM\ManyToOne(targetEntity: LegacyDBGmbCategoryModel::class)]
     * #[ORM\JoinColumn(name: '`item_id`', referencedColumnName: 'id')]
     * public ?LegacyDBGmbCategoryModel $gmb_category;
     *
     * @return string[] The list of columns.
     * @psalm-return list<string>
     */
    protected function getInsertColumnList()
    {
        $columns = [];

        foreach ($this->class->reflFields as $name => $field) {
            if ($this->class->isVersioned && $this->class->versionField === $name) {
                continue;
            }

            if (isset($this->class->embeddedClasses[$name])) {
                continue;
            }

            if (isset($this->class->associationMappings[$name])) {
                $assoc = $this->class->associationMappings[$name];

                if ($assoc['isOwningSide'] && $assoc['type'] & ClassMetadata::TO_ONE) {
                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        $columns[$this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform)] = true;
                    }
                }

                continue;
            }

            if (! $this->class->isIdGeneratorIdentity() || $this->class->identifier[0] !== $name) {
                if (isset($this->class->fieldMappings[$name]['notInsertable'])) {
                    continue;
                }

                $columns[$this->quoteStrategy->getColumnName($name, $this->class, $this->platform)] = true;
                $this->columnTypes[$name] = $this->class->fieldMappings[$name]['type'];
            }
        }

        return array_keys($columns);
    }
}
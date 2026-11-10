<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Hydration;

use Doctrine\ORM\Internal\Hydration\ObjectHydrator as DoctrineObjectHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Doctrine's object hydrator without the eager load of inverse to-one associations.
 *
 * Doctrine cannot put a proxy on the inverse side of a one-to-one relation (the row that holds no foreign key does
 * not know whether a counterpart exists), so for every hydrated row it reads the counterpart with a statement of its
 * own: 50 parents with two such relations are 100 extra statements, each loading the whole child row, whether or not
 * anything asks for it. The framework does not need that load: a relation is read by an expand (joined in the same
 * statement) or by its lazy-loaded property (through the repository).
 *
 * This hydrator therefore marks every inverse to-one association that the statement does not join as fetched, which
 * is the unit of work's own switch for "do not load this association". The model property then stays uninitialized,
 * which the mapper reads as "not loaded".
 */
class LazyInverseToOneObjectHydrator extends DoctrineObjectHydrator
{
    /**
     * {@inheritDoc}
     */
    protected function prepare()
    {
        parent::prepare();

        foreach ($this->resultSetMapping()->aliasMap as $dqlAlias => $className) {
            foreach ($this->getClassMetadata($className)->associationMappings as $associationMapping) {
                $isInverseToOne = ($associationMapping['type'] & ClassMetadata::TO_ONE) && !$associationMapping['isOwningSide'];
                if ($isInverseToOne && !isset($this->_hints['fetched'][$dqlAlias][$associationMapping['fieldName']])) {
                    $this->_hints['fetched'][$dqlAlias][$associationMapping['fieldName']] = true;
                }
            }
        }
    }
}

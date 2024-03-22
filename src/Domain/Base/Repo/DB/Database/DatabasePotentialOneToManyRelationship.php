<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

#[Attribute(Attribute::TARGET_PROPERTY)]
/**
 * Represents a potential one to many relationship based on an Entity's property of type EntitySet.
 * Potential means, that it has first to be verified if all precodintions are met to add it as OneToMany
 * e.g. the singular Entity of the referenced EntitySet needs to havce a property containing the id of the
 * mapping class Entity
 */
class DatabasePotentialOneToManyRelationship extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string Property name for Relationship */
    public string $propertyName;

    /** @var string Target EntitySet class referenced in Entity property as potential representation of a one to many relationship */
    public string $targetEntitySetClass;

    public function __construct(string $propertyName, string $targetEntitySetClass)
    {
        $this->propertyName = $propertyName;
        $this->targetEntitySetClass = $targetEntitySetClass;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->propertyName);
    }


}
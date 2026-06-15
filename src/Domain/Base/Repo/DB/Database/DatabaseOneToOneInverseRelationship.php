<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseOneToOneInverseRelationship extends ValueObject
{
    use BaseAttributeTrait;

    public string $propertyName;          // inverse (parent) property holding the single reference back
    public string $targetModelName;       // short class name of the owning ORM model carrying the FK
    public string $mappedByPropertyName;  // owning property on the target whose {name}Id is the FK (Doctrine mappedBy)

    public function __construct(string $propertyName, string $targetModelName, string $mappedByPropertyName)
    {
        $this->propertyName = $propertyName;
        $this->targetModelName = $targetModelName;
        $this->mappedByPropertyName = $mappedByPropertyName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->propertyName);
    }
}

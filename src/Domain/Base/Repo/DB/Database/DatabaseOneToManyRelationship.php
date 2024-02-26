<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseOneToManyRelationship extends ValueObject
{
    use BaseAttributeTrait;
    
    /** @var string Property name for Relationship */
    public string $propertyName;

    /** @var */
    public string $targetModelName;

    public string $mappedByPropertyName;

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
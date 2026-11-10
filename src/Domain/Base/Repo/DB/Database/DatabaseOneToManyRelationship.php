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

    /** @var string The target model as the generated code writes it: its short name, or its fully qualified name when the short name is taken */
    public string $targetModelReferenceName;

    public function __construct(string $propertyName, string $targetModelName, string $mappedByPropertyName, ?string $targetModelReferenceName = null)
    {
        $this->targetModelReferenceName = $targetModelReferenceName ?? $targetModelName;
        $this->propertyName = $propertyName;
        $this->targetModelName = $targetModelName;
        $this->mappedByPropertyName = $mappedByPropertyName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->propertyName);
    }


}
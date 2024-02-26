<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/** Returns parameter as enum with class name as only number */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Enum extends Base
{
    use BaseAttributeTrait;

    /** @var mixed[] */
    private array $enumValues = [];

    public function __construct(mixed ...$enumValues)
    {
        if (count($enumValues) === 1 && is_array(current($enumValues))) {
            $enumValues = [...current($enumValues)];
        }
        $this->enumValues = $enumValues;
    }

    /**
     * @return mixed[]
     */
    public function getEnumValues(): array
    {
        return $this->enumValues;
    }

}
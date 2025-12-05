<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Infrastructure\Base\DateTime\Date;

class DateValue extends BaseObject {
    public Date $x;

    /** @var float|null Value on the chart for the corresponding date */
    public ?float $y;

    /**
     * individualized toObject function for efficiency purposes without using SerializerTrait
     * @param $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @param bool $forPersistence
     * @param int $flags
     * @return array
     */
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): array {
        return ['x' => (string) $this->x, 'y' => $this->y??null];
    }

    public function jsonSerialize()
    {
        return $this->toObject();
    }

    public function __toString()
    {
        return $this->toJSON();
    }

    public function toJSON()
    {
        return json_encode($this->jsonSerialize(), JSON_UNESCAPED_SLASHES);
    }

    public function uniqueKey(): string {
        return (string) $this->x;
    }

    public function equals(BaseObject &$other): bool
    {
        return $this->uniqueKey() == $other->uniqueKey();
    }

    public function setPropertiesFromObject(&$object, $throwErrors = true, bool $rootCall = true, bool $sanitizeInput = false): void
    {
        if (isset($object->x)){
            $this->x = Date::fromString($object->x);
        }
        if (isset($object->y)){
            $this->y = (float) $object->y;
        }
    }
}
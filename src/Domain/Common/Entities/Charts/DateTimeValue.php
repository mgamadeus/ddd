<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A point of a time series at a moment (minute, hour, day — {@see DateValue} is the day-only one): `x` the moment (for
 * a bucketed series the bucket's start), `y` the value. Serialises as `{"x": "2026-10-28T10:00:00Z", "y": 4}` (UTC).
 */
class DateTimeValue extends BaseObject
{
    public DateTime $x;

    public ?float $y = null;

    public function __construct(?DateTime $x = null, ?float $y = null)
    {
        if ($x !== null) {
            $this->x = $x;
        }
        $this->y = $y;
    }

    /** `x` as ISO 8601 in UTC */
    public function getXAsString(): string
    {
        return gmdate(DateTime::DATE_RFC3339_ZULU, $this->x->getTimestamp());
    }

    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): array {
        return ['x' => $this->getXAsString(), 'y' => $this->y];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toObject();
    }

    public function __toString(): string
    {
        return $this->toJSON();
    }

    public function toJSON(): string
    {
        return (string)json_encode($this->jsonSerialize(), JSON_UNESCAPED_SLASHES);
    }

    public function uniqueKey(): string
    {
        return (string)$this->x->getTimestamp();
    }

    public function equals(BaseObject &$other): bool
    {
        return $this->uniqueKey() === $other->uniqueKey();
    }

    public function setPropertiesFromObject(&$object, $throwErrors = true, bool $rootCall = true, bool $sanitizeInput = false): void
    {
        if (isset($object->x)) {
            $this->x = DateTime::fromTimestamp((int)strtotime((string)$object->x));
        }
        if (isset($object->y)) {
            $this->y = (float)$object->y;
        }
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

abstract class BaseObject
{
    public static function uniqueKeyStatic(string|int $id = null): string
    {
        return static::class . ($id ? '_' . $id : '');
    }

    abstract public function uniqueKey(): string;

    /**
     * Verifies Identity by uniqueKey
     * @param BaseObject $other
     * @return bool
     */
    public function equals(BaseObject &$other): bool
    {
        $thisClass = static::class;
        $otherClass = $other::class;

        // Use is_a with allow_string=true to check for same class or inheritance relationships.
        if (!(is_a($thisClass, $otherClass, true) || is_a($otherClass, $thisClass, true))) {
            return false;
        }
        return $this->uniqueKey() == $other->uniqueKey();
    }
}
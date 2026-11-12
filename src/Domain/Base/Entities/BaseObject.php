<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

abstract class BaseObject
{
    /**
     * The key of an object of this class with the given id: `<class>_<id>`, the bare class name without an id
     * (null or ''). The id 0 / '0' is an id like any other. The key carries the class it is called on: a lookup through
     * a parent class does not find an element of a derived class — key such elements by the declaring class
     * (`self::class`) in both `uniqueKey()` and the lookup.
     */
    public static function uniqueKeyStatic(string|int|null $id = null): string
    {
        return static::class . ($id === null || $id === '' ? '' : '_' . $id);
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
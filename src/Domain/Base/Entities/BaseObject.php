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

    public function equals(BaseObject &$other): bool
    {
        return $this->uniqueKey() == $other->uniqueKey();
    }
}
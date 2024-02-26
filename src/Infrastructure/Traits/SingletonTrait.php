<?php

namespace DDD\Infrastructure\Traits;

use RuntimeException;

trait SingletonTrait
{

    /**
     * Array containing the Singleton subclasses' instances
     *
     * @var SingletonTrait[]
     */
    private static array $instances = [];

    /**
     * Returns an instance of the current Singleton subclass
     * Creates the instance if it doesn't already exist before returning it
     *
     * @return static
     */
    public static function getInstance(mixed &...$params): static
    {
        $subclass = static::class;
        if (!isset(self::$instances[$subclass])) {
            self::$instances[$subclass] = new static(...$params);
        }

        return self::$instances[$subclass];
    }

    /**
     * @return void
     */
    public function __wakeup(): void
    {
        throw new RuntimeException('Cannot deserialize singleton');
    }

    /**
     * Cloning and deserialization are not permitted for singletons.
     */
    protected function __clone() {}

}
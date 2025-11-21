<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\AfterConstruct;

use DDD\Infrastructure\Traits\AfterConstruct\Attributes\AfterConstruct;
use DDD\Infrastructure\Traits\ReflectorTrait;
use ReflectionAttribute;

trait AfterConstructTrait
{
    use ReflectorTrait;

    /**
     * This is a helper function that enables to run code of e.g. various traits after the constructor is called
     * should be included in __consturct() at the end:
     * executes all functions with the prefix _afterConstruct
     * @return void
     */
    public function afterConstruct()
    {
        if (!AfterConstruct::$afterConstructMethods) {
            AfterConstruct::$afterConstructMethods = [];
        }
        if (isset(AfterConstruct::$afterConstructMethods[static::class])) {
            if (empty(AfterConstruct::$afterConstructMethods[static::class])) {
                return;
            } else {
                foreach (AfterConstruct::$afterConstructMethods[static::class] as $methodName) {
                    $this->$methodName();
                }
                return;
            }
        }
        AfterConstruct::$afterConstructMethods[static::class] = [];
        foreach (static::getReflectionClass()->getMethods() as $method) {
            if ($method->getAttributes(AfterConstruct::class, ReflectionAttribute::IS_INSTANCEOF)) {
                $methodName = $method->getName();
                AfterConstruct::$afterConstructMethods[static::class][] = $methodName;
                $this->$methodName();
            }
        }
    }
}
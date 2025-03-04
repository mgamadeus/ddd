<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Infrastructure\Libs\Config;
use ReflectionClassConstant;
use ReflectionException;
use RuntimeException;

class ReflectionEnum extends \ReflectionEnum
{

    public static $relectionEnumCache = [];
    public static $enumOptionsCache = [];

    public const NO_REFLECTION = 'no_reflection';

    /**
     * returns an instance of reflection class
     * instances are statically cached per className
     * @param string $className
     * @return mixed|ReflectionClass
     * @throws ReflectionException
     */
    public static function instance(string $enumClassName): ?ReflectionEnum
    {
        if (isset(self::$relectionEnumCache[$enumClassName])) {
            $cached = self::$relectionClassCache[$enumClassName];
            return $cached === self::NO_REFLECTION ? null : $cached;
        }

        try {
            $reflection = new ReflectionEnum($enumClassName);
            self::$relectionEnumCache[$enumClassName] = $reflection;
            return $reflection;
        } catch (ReflectionException $e) {
            self::$relectionEnumCache[$enumClassName] = self::NO_REFLECTION;
            return null;
        }
    }

    /**
     * Returns an array of all backed values of the Enum cases.
     * The results are cached statically to improve performance.
     *
     * @return array
     */
    public function getEnumValues(): array
    {
        /** @var Enum $enumClassName */
        $enumClassName = $this->getName();

        if (isset(self::$enumOptionsCache[$enumClassName])) {
            return $cache[$enumClassName];
        }
        $cases = $enumClassName::cases();
        $options = [];

        foreach ($cases as $case) {
            $value = $this->isBacked() ? $case->value : $case->name;
            $options[$value] = $case;
        }

        self::$enumOptionsCache[$enumClassName] = $options;

        return $options;
    }

    // implement getEnumOptions
}
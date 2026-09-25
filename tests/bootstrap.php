<?php

declare(strict_types=1);

/**
 * Framework objects assume a booted kernel (DefaultObject's lazy-load reflection reaches for DDDService, which
 * reaches for the container). The suite therefore installs a MINIMAL container: real parameters a test can set,
 * and services resolved by instantiating the requested class.
 */

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

require __DIR__ . '/../vendor/autoload.php';

class TestContainer extends Container
{
    protected array $testInstances = [];

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if ($id === 'kernel') {
            // DDDService::getConsoleDir() asks the kernel for its prefix; a stub keeps console-path code testable
            // without booting one.
            return new class {
                public function getKernelPrefix(): string
                {
                    return '';
                }
            };
        }
        if (!isset($this->testInstances[$id])) {
            if (!class_exists($id)) {
                return null;
            }
            $reflectionClass = new ReflectionClass($id);
            $this->testInstances[$id] = $reflectionClass->getConstructor()
            && $reflectionClass->getConstructor()->getNumberOfRequiredParameters() > 0
                ? $reflectionClass->newInstanceWithoutConstructor()
                : new $id();
        }
        return $this->testInstances[$id];
    }

    public function has(string $id): bool
    {
        return class_exists($id) || parent::has($id);
    }
}

$testCacheDir = sys_get_temp_dir() . '/ddd_phpunit_cache';
@mkdir($testCacheDir, 0777, true);
@touch($testCacheDir . '/service_class_map.php');

$testContainer = new TestContainer(new ParameterBag([
    'kernel.cache_dir' => $testCacheDir,
    'kernel.project_dir' => dirname(__DIR__),
    'kernel.environment' => 'test',
]));
(new ReflectionProperty(DDD\DDDBundle::class, 'defaultContainer'))->setValue(null, $testContainer);

/** @return TestContainer The container the suite installed, for tests that set parameters on it */
function dddTestContainer(): TestContainer
{
    return DDD\DDDBundle::getContainer();
}

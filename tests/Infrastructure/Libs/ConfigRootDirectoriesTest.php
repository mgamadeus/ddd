<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Libs;

use DDD\Infrastructure\Libs\Config;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The public root list: application roots first (newest first), then module roots (newest first). */
class ConfigRootDirectoriesTest extends TestCase
{
    /** @var array<string, string[]> the static root lists before the test */
    protected array $rootDirectoriesBefore = [];

    protected function setUp(): void
    {
        foreach (['appRootDirectories', 'moduleRootDirectories'] as $propertyName) {
            $rootDirectoriesProperty = new ReflectionProperty(Config::class, $propertyName);
            $this->rootDirectoriesBefore[$propertyName] = $rootDirectoriesProperty->getValue();
            $rootDirectoriesProperty->setValue(null, []);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->rootDirectoriesBefore as $propertyName => $rootDirectories) {
            (new ReflectionProperty(Config::class, $propertyName))->setValue(null, $rootDirectories);
        }
    }

    public function testApplicationRootsComeFirstThenModuleRootsEachNewestFirst(): void
    {
        $moduleRootA = sys_get_temp_dir() . '/ddd-config-root-module-a';
        $moduleRootB = sys_get_temp_dir() . '/ddd-config-root-module-b';
        $applicationRoot = sys_get_temp_dir() . '/ddd-config-root-application';
        foreach ([$moduleRootA, $moduleRootB, $applicationRoot] as $rootDirectory) {
            @mkdir($rootDirectory, 0777, true);
        }

        Config::addConfigDirectory($moduleRootA, true);
        Config::addConfigDirectory($applicationRoot);
        Config::addConfigDirectory($moduleRootB, true);
        Config::addConfigDirectory(sys_get_temp_dir() . '/ddd-config-root-missing', true);

        $this->assertSame([$applicationRoot, $moduleRootB, $moduleRootA], Config::getConfigRootDirectories());
    }
}

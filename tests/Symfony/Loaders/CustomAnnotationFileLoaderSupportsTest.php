<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Loaders;

use DDD\Symfony\Loaders\CustomAnnotationFileLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Config\FileLocator;

/**
 * Symfony 7.4 asks apps to import FrameworkBundle's errors.php / wdt.php / profiler.php instead of the deprecated XML
 * files. Imported without `type: php`, those configuration files must reach Symfony's PhpFileLoader — if this loader
 * claimed them it would read them as a class, find no routes, and every profiler / toolbar / error route would vanish.
 */
class CustomAnnotationFileLoaderSupportsTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ddd_loader_supports_' . uniqid();
        mkdir($this->directory);
        file_put_contents(
            $this->directory . '/errors.php',
            "<?php\nuse Symfony\\Component\\Routing\\Loader\\Configurator\\RoutingConfigurator;\n"
            . "return static function (RoutingConfigurator \$routes): void {\n    \$routes->add('_preview_error', '/{code}');\n};\n"
        );
        file_put_contents(
            $this->directory . '/ProbeController.php',
            "<?php\nnamespace Probe;\nclass ProbeController\n{\n}\n"
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*'));
        rmdir($this->directory);
    }

    protected function fileLoader(): CustomAnnotationFileLoader
    {
        /** @var CustomAnnotationFileLoader $fileLoader */
        $fileLoader = (new ReflectionClass(CustomAnnotationFileLoader::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($fileLoader, 'locator'))->setValue($fileLoader, new FileLocator([$this->directory]));
        return $fileLoader;
    }

    public function testAnUntypedRoutingConfigurationFileIsLeftToThePhpFileLoader(): void
    {
        $this->assertFalse($this->fileLoader()->supports($this->directory . '/errors.php'));
    }

    public function testAnUntypedControllerClassFileIsClaimed(): void
    {
        $this->assertTrue($this->fileLoader()->supports($this->directory . '/ProbeController.php'));
    }

    public function testAnAttributeTypedImportIsAlwaysClaimed(): void
    {
        $this->assertTrue($this->fileLoader()->supports($this->directory . '/errors.php', 'attribute'));
    }

    public function testAPhpTypedImportIsNeverClaimed(): void
    {
        $this->assertFalse($this->fileLoader()->supports($this->directory . '/ProbeController.php', 'php'));
    }
}

<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Docblocks;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\ErrorHandler\DebugClassLoader;

/**
 * Symfony's DebugClassLoader (active in every app's debug mode) reports, on loading, a framework override that does not
 * declare an argument its parent announces — a parent docblock `@param mixed $where` on a func_get_args() method, or a
 * phantom `@param` — as "will require a new … argument". Apps see each one in their container deprecation log. Loading
 * all of src/ once under the loader must report none about a framework class. (The loader's return-type and final-class
 * hints are not what apps see and are not asserted here.) Runs in a separate process: the loader wraps the autoloader.
 *
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
class DebugClassLoaderDeprecationsTest extends TestCase
{
    public function testLoadingEveryFrameworkClassReportsNoDeprecationAboutIt(): void
    {
        require dirname(__DIR__, 3) . '/vendor/autoload.php';
        $sourceDirectory = dirname(__DIR__, 3) . '/src';
        $frameworkDeprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$frameworkDeprecations): bool {
            if (
                ($type === E_USER_DEPRECATED || $type === E_DEPRECATED)
                && str_contains($message, '"DDD\\')
                && str_contains($message, 'argument in the next major version')
            ) {
                $frameworkDeprecations[] = $message;
            }
            return true;
        });
        DebugClassLoader::enable();
        try {
            $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory));
            foreach ($sourceFiles as $sourceFile) {
                if ($sourceFile->getExtension() !== 'php') {
                    continue;
                }
                $relativePath = substr($sourceFile->getPathname(), strlen($sourceDirectory) + 1, -4);
                $className = 'DDD\\' . str_replace('/', '\\', $relativePath);
                try {
                    class_exists($className) || interface_exists($className) || trait_exists($className);
                } catch (\Throwable) {
                    // a class whose optional dependency is not installed here; not this test's concern
                }
            }
        } finally {
            DebugClassLoader::disable();
            restore_error_handler();
        }

        $this->assertSame([], array_values(array_unique($frameworkDeprecations)));
    }
}

<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Docblocks;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Every @param in a public or protected method's docblock must name a real parameter. Symfony's DebugClassLoader reads
 * a @param that is missing from the signature as an argument announced for the next major and raises a deprecation in
 * EVERY app class that overrides the method (2.70.3: SerializerTrait's $rootCall; 2.70.5: AccountsService's
 * $accountId and 32 more). The bundled Predis copy is third-party code and is left out.
 */
class DocblockParamNamesTest extends TestCase
{
    public function testEveryParamTagNamesARealParameter(): void
    {
        $ghostParams = [];
        $sourceDirectory = dirname(__DIR__, 3) . '/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory)) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Cache/Predis/')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (
                !preg_match('/^namespace\s+([^;]+);/m', $code, $namespaceMatch)
                || !preg_match('/^\s*(?:abstract\s+|final\s+)*(?:class|trait|interface)\s+(\w+)/m', $code, $classMatch)
            ) {
                continue;
            }
            $className = $namespaceMatch[1] . '\\' . $classMatch[1];
            try {
                if (!class_exists($className) && !trait_exists($className) && !interface_exists($className)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            foreach ((new ReflectionClass($className))->getMethods() as $method) {
                if ($method->getFileName() !== $file->getRealPath() || $method->isPrivate() || !($docComment = $method->getDocComment())) {
                    continue;
                }
                preg_match_all('/@param\s+[^\s$]*\s*(?:\.\.\.)?\$(\w+)/', $docComment, $paramMatches);
                $realParameterNames = array_map(static fn ($parameter) => $parameter->getName(), $method->getParameters());
                foreach (array_diff($paramMatches[1], $realParameterNames) as $ghostParam) {
                    $ghostParams[] = basename($file->getPathname()) . ' ' . $method->getName() . "(): \$$ghostParam";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($ghostParams)));
    }
}

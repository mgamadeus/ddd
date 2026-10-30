<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every class Core fetches with `getService(X::class)` lives in a namespace the standard services.yaml registers as
 * PUBLIC services. A class outside them is private in an application's compiled container: removed or inlined, and the
 * fetch fails at runtime ("service or alias has been removed or inlined") — while a test kernel that registers the
 * class differently stays green.
 */
class ContainerFetchedServicesNamespaceTest extends TestCase
{
    /** @return string[] The namespaces services.yaml registers with `public: true` */
    protected function getPublicServiceNamespaces(): array
    {
        $publicServiceNamespaces = [];
        $currentNamespace = null;
        foreach (file(dirname(__DIR__, 3) . '/config/symfony/default/services.yaml') ?: [] as $line) {
            if (preg_match('/^    (DDD\\\\\S*\\\\):\s*$/', rtrim($line), $matches)) {
                $currentNamespace = $matches[1];
            } elseif (preg_match('/^    \S/', $line)) {
                $currentNamespace = null;
            } elseif ($currentNamespace !== null && trim($line) === 'public: true') {
                $publicServiceNamespaces[] = $currentNamespace;
            }
        }
        return $publicServiceNamespaces;
    }

    public function testEveryContainerFetchedClassIsAPublicService(): void
    {
        $publicServiceNamespaces = $this->getPublicServiceNamespaces();
        $this->assertContains('DDD\\Infrastructure\\Services\\', $publicServiceNamespaces);
        $this->assertContains('DDD\\Domain\\Common\\Services\\', $publicServiceNamespaces);

        $violations = [];
        $sourceDirectory = dirname(__DIR__, 3) . '/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory)) as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($fileInfo->getPathname());
            if (!preg_match_all('/getService\(\s*\\\\?([A-Za-z_\\\\]+)::class\s*\)/', $source, $serviceMatches)) {
                continue;
            }
            preg_match('/^namespace ([^;]+);/m', $source, $namespaceMatch);
            preg_match_all('/^use ([A-Za-z_\\\\]+)(?: as ([A-Za-z_]+))?;/m', $source, $useMatches, PREG_SET_ORDER);
            $classesByShortName = [];
            foreach ($useMatches as $useMatch) {
                $classesByShortName[$useMatch[2] ?? '' ?: substr($useMatch[1], (int)strrpos($useMatch[1], '\\') + 1)] = $useMatch[1];
            }
            foreach ($serviceMatches[1] as $referencedClass) {
                if (in_array($referencedClass, ['self', 'static'], true)) {
                    continue;
                }
                $serviceClass = str_contains($referencedClass, '\\')
                    ? ltrim($referencedClass, '\\')
                    : ($classesByShortName[$referencedClass] ?? ($namespaceMatch[1] ?? '') . '\\' . $referencedClass);
                if (!str_starts_with($serviceClass, 'DDD\\')) {
                    continue;
                }
                $isPublic = false;
                foreach ($publicServiceNamespaces as $publicServiceNamespace) {
                    $isPublic = $isPublic || str_starts_with($serviceClass, $publicServiceNamespace);
                }
                if (!$isPublic) {
                    $violations[] = substr($fileInfo->getPathname(), strlen($sourceDirectory) + 1) . ': ' . $serviceClass;
                }
            }
        }

        $this->assertSame([], $violations, 'fetched with getService() but not in a public service namespace of services.yaml');
    }
}

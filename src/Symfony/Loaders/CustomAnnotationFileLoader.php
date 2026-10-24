<?php

namespace DDD\Symfony\Loaders;

use ReflectionClass;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\HttpKernel\Config\FileLocator;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

class CustomAnnotationFileLoader extends AttributeFileLoader
{
    public function __construct(FileLocator $locator, CustomAnnotationClassLoader $loader)
    {
        parent::__construct($locator, $loader);
    }

    public function load(mixed $file, ?string $type = null): ?RouteCollection
    {
        $path = $this->locator->locate($file);

        $collection = new RouteCollection();
        if ($class = $this->findClass($path)) {
            $refl = new ReflectionClass($class);
            if ($refl->isAbstract()) {
                return null;
            }

            $collection->addResource(new FileResource($path));
            $collection->addCollection($this->loader->load($class, $type));
        }

        gc_mem_caches();

        return $collection;
    }

    /**
     * An untyped `.php` resource is claimed only when it declares a class. A routing CONFIGURATION file returns a
     * RoutingConfigurator closure and declares none — FrameworkBundle's errors.php / wdt.php / profiler.php, which
     * Symfony 7.4 asks apps to import instead of the deprecated XML files. Claiming those made this loader read them
     * as a class, find no routes and leave every profiler / web-debug-toolbar / error route out, silently, because
     * Symfony's PhpFileLoader never ran. `type: attribute` is always ours; `type: php` never reaches here.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        if (!parent::supports($resource, $type)) {
            return false;
        }
        if ($type === 'attribute') {
            return true;
        }
        try {
            $path = $this->locator->locate($resource);
        } catch (Throwable) {
            // not locatable here: keep the previous behaviour and let load() report it
            return true;
        }
        return is_string($path) && $this->findClass($path) !== false;
    }
}
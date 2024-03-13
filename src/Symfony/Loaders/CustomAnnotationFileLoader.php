<?php

namespace DDD\Symfony\Loaders;

use ReflectionClass;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\HttpKernel\Config\FileLocator;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\RouteCollection;

class CustomAnnotationFileLoader extends AttributeFileLoader
{
    public function __construct2(FileLocator $locator, CustomAnnotationClassLoader $loader)
    {
        parent::__construct($locator, $loader);
    }

    public function __construct(FileLocatorInterface $locator, CustomAnnotationClassLoader $loader)
    {
        parent::__construct($locator, $loader);
    }

    public function load(mixed $file, string $type = null): ?RouteCollection
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

}
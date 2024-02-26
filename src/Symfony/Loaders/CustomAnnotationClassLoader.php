<?php

namespace DDD\Symfony\Loaders;

use Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Annotation\Route as RouteAnnotation;
use Symfony\Component\Routing\RouteCollection;

class CustomAnnotationClassLoader extends AnnotatedRouteControllerLoader
{
    protected function resetGlobals(): array
    {
        return [
            'path' => null,
            'localized_paths' => [],
            'requirements' => [],
            'options' => [],
            'defaults' => [],
            'schemes' => [],
            'methods' => [],
            'host' => '',
            'condition' => '',
            'name' => '',
            'priority' => 0,
            'env' => null,
        ];
    }

    /**
     * Loads from annotations from a class.
     *
     * @throws \InvalidArgumentException When route can't be parsed
     */
    public function load(mixed $class, string $type = null): RouteCollection
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $class));
        }

        $class = new \ReflectionClass($class);
        if ($class->isAbstract()) {
            throw new \InvalidArgumentException(sprintf('Annotations from class "%s" cannot be read as it is abstract.', $class->getName()));
        }
        $collection = new RouteCollection();

        $collection->addResource(new FileResource($class->getFileName()));

        $allGlobals = $this->getGlobals($class);

        foreach ($allGlobals as $globals) {
            if ($globals['env'] && $this->env !== $globals['env']) {
                return $collection;
            }
            /** @var Route $annot */
            foreach ($class->getMethods() as $method) {
                $this->defaultRouteIndex = 0;
                foreach ($this->getAnnotations($method) as $annot) {
                    if (!$annot->getName())
                        $annot->setName(implode(',',$annot->getMethods()) . ':' . $globals['path'] . $annot->getPath());
                    $this->addRoute($collection, $annot, $globals, $class, $method);
                }
            }

            if (0 === $collection->count() && $class->hasMethod('__invoke')) {
                $globals = $this->resetGlobals();
                foreach ($this->getAnnotations($class) as $annot) {
                    if (!$annot->getName())
                        $annot->setName(implode(',',$annot->getMethods()) .':'. $globals['path'] . $annot->getPath());
                    $this->addRoute($collection, $annot, $globals, $class, $class->getMethod('__invoke'));
                }
            }
        }

        return $collection;
    }

    /**
     * @param \ReflectionClass|\ReflectionMethod $reflection
     *
     * @return iterable<int, RouteAnnotation>
     */
    protected function getAnnotations(object $reflection): iterable
    {
        foreach ($reflection->getAttributes($this->routeAnnotationClass, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            yield $attribute->newInstance();
        }

        if (!$this->reader) {
            return;
        }

        $anntotations = $reflection instanceof \ReflectionClass
            ? $this->reader->getClassAnnotations($reflection)
            : $this->reader->getMethodAnnotations($reflection);

        foreach ($anntotations as $annotation) {
            if ($annotation instanceof $this->routeAnnotationClass) {
                yield $annotation;
            }
        }
    }


    protected function getGlobals(\ReflectionClass $class)
    {
        //echo $class->getName() . '<br />';die();
        $annot = null;
        $annotations = $class->getAttributes($this->routeAnnotationClass, \ReflectionAttribute::IS_INSTANCEOF   ) ?? null;

        $allGlobals = [];
        if ($annotations){
            foreach ($annotations as $annot){
                /** @var Route $annot */
                $annot = $annot->newInstance();
                $globals = $this->resetGlobals();
                if (null !== $annot->getName()) {
                    $globals['name'] = $annot->getName();
                }

                if (null !== $annot->getPath()) {
                    $globals['path'] = $annot->getPath();
                }

                $globals['localized_paths'] = $annot->getLocalizedPaths();

                if (null !== $annot->getRequirements()) {
                    $globals['requirements'] = $annot->getRequirements();
                }

                if (null !== $annot->getOptions()) {
                    $globals['options'] = $annot->getOptions();
                }

                if (null !== $annot->getDefaults()) {
                    $globals['defaults'] = $annot->getDefaults();
                }

                if (null !== $annot->getSchemes()) {
                    $globals['schemes'] = $annot->getSchemes();
                }

                if (null !== $annot->getMethods()) {
                    $globals['methods'] = $annot->getMethods();
                }

                if (null !== $annot->getHost()) {
                    $globals['host'] = $annot->getHost();
                }

                if (null !== $annot->getCondition()) {
                    $globals['condition'] = $annot->getCondition();
                }

                $globals['priority'] = $annot->getPriority() ?? 0;
                $globals['env'] = $annot->getEnv();

                foreach ($globals['requirements'] as $placeholder => $requirement) {
                    if (\is_int($placeholder)) {
                        throw new \InvalidArgumentException(sprintf('A placeholder name must be a string (%d given). Did you forget to specify the placeholder key for the requirement "%s" in "%s"?', $placeholder, $requirement, $class->getName()));
                    }
                }
                $allGlobals[] = $globals;
            }
        }
        return $allGlobals;
    }
}
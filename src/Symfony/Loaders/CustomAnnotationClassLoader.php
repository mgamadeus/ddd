<?php

namespace DDD\Symfony\Loaders;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Annotation\Route as RouteAnnotation;
use Symfony\Component\Routing\RouteCollection;

use function is_int;

class CustomAnnotationClassLoader extends AttributeRouteControllerLoader
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
     * @throws InvalidArgumentException When route can't be parsed
     */
    public function load(mixed $class, ?string $type = null): RouteCollection
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Class "%s" does not exist.', $class));
        }

        $class = new ReflectionClass($class);
        if ($class->isAbstract()) {
            throw new InvalidArgumentException(sprintf('Annotations from class "%s" cannot be read as it is abstract.', $class->getName()));
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
                    if (!$annot->getName()) {
                        //$annot->setName(implode(',', $annot->getMethods()) . ':' . $globals['path'] . $annot->getPath());
                        $annot->setName($class->getName() . ':'.$method->getName() . '__' . implode(',', $annot->getMethods()) . ':' . $globals['path'] . $annot->getPath());
                    }
                    $this->addRoute($collection, $annot, $globals, $class, $method);
                }
            }

            if (0 === $collection->count() && $class->hasMethod('__invoke')) {
                $globals = $this->resetGlobals();
                foreach ($this->getAnnotations($class) as $annot) {
                    if (!$annot->getName()) {
                        $annot->setName(implode(',', $annot->getMethods()) . ':' . $globals['path'] . $annot->getPath());
                    }
                    $this->addRoute($collection, $annot, $globals, $class, $class->getMethod('__invoke'));
                }
            }
        }

        return $collection;
    }

    /**
     * @param ReflectionClass|ReflectionMethod $reflection
     *
     * @return iterable<int, RouteAnnotation>
     */
    protected function getAnnotations(ReflectionClass|ReflectionMethod $reflection): iterable
    {
        foreach ($reflection->getAttributes($this->routeAnnotationClass, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            yield $attribute->newInstance();
        }
    }

    protected function getGlobals(ReflectionClass $class): array
    {
        //echo $class->getName() . '<br />';die();
        $annot = null;
        $attributes = $class->getAttributes($this->routeAnnotationClass, ReflectionAttribute::IS_INSTANCEOF) ?? null;

        $allGlobals = [];
        if ($attributes) {
            foreach ($attributes as $attribute) {
                /** @var ReflectionAttribute $attribute */
                $attribute = $attribute->newInstance();

                /** @var Route $attribute */

                $globals = $this->resetGlobals();
                if (null !== $attribute->getName()) {
                    $globals['name'] = $attribute->getName();
                }

                if (null !== $attribute->getPath()) {
                    $globals['path'] = $attribute->getPath();
                }

                $globals['localized_paths'] = $attribute->getLocalizedPaths();

                if (null !== $attribute->getRequirements()) {
                    $globals['requirements'] = $attribute->getRequirements();
                }

                if (null !== $attribute->getOptions()) {
                    $globals['options'] = $attribute->getOptions();
                }

                if (null !== $attribute->getDefaults()) {
                    $globals['defaults'] = $attribute->getDefaults();
                }

                if (null !== $attribute->getSchemes()) {
                    $globals['schemes'] = $attribute->getSchemes();
                }

                if (null !== $attribute->getMethods()) {
                    $globals['methods'] = $attribute->getMethods();
                }

                if (null !== $attribute->getHost()) {
                    $globals['host'] = $attribute->getHost();
                }

                if (null !== $attribute->getCondition()) {
                    $globals['condition'] = $attribute->getCondition();
                }

                $globals['priority'] = $attribute->getPriority() ?? 0;
                $globals['env'] = $attribute->getEnv();

                foreach ($globals['requirements'] as $placeholder => $requirement) {
                    if (is_int($placeholder)) {
                        throw new InvalidArgumentException(
                            sprintf(
                                'A placeholder name must be a string (%d given). Did you forget to specify the placeholder key for the requirement "%s" in "%s"?',
                                $placeholder,
                                $requirement,
                                $class->getName()
                            )
                        );
                    }
                }
                $allGlobals[] = $globals;
            }
        }
        return $allGlobals;
    }
}
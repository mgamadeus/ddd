<?php

namespace DDD\Symfony\Loaders;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouteCollection;

use function in_array;
use function is_int;
use function is_string;

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
            if ($globals['env'] && !in_array($this->env, $globals['env'], true)) {
                return $collection;
            }
            /** @var Route $annot */
            foreach ($class->getMethods() as $method) {
                $this->defaultRouteIndex = 0;
                foreach ($this->getAnnotations($method) as $annot) {
                    if (!$annot->name) {
                        $annot->name = $class->getName() . ':' . $method->getName() . '__'
                            . implode(',', $annot->methods) . ':' . $globals['path'] . $this->getStringPath($annot);
                    }
                    $this->addRoute($collection, $annot, $globals, $class, $method);
                }
            }

            if (0 === $collection->count() && $class->hasMethod('__invoke')) {
                $globals = $this->resetGlobals();
                foreach ($this->getAnnotations($class) as $annot) {
                    if (!$annot->name) {
                        $annot->name = implode(',', $annot->methods) . ':' . $globals['path'] . $this->getStringPath($annot);
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
     * @return iterable<int, Route>
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
        $attributes = $class->getAttributes($this->routeAnnotationClass, ReflectionAttribute::IS_INSTANCEOF) ?? null;

        $allGlobals = [];
        if ($attributes) {
            foreach ($attributes as $attribute) {
                /** @var ReflectionAttribute $attribute */
                $attribute = $attribute->newInstance();

                /** @var Route $attribute */

                $globals = $this->resetGlobals();
                if (null !== $attribute->name) {
                    $globals['name'] = $attribute->name;
                }

                // a string is the path; an array holds the localized paths (Symfony 7.4 dropped $localizedPaths)
                if (is_string($attribute->path)) {
                    $globals['path'] = $attribute->path;
                    $globals['localized_paths'] = [];
                } else {
                    $globals['localized_paths'] = $attribute->path ?? [];
                }

                if (null !== $attribute->requirements) {
                    $globals['requirements'] = $attribute->requirements;
                }

                if (null !== $attribute->options) {
                    $globals['options'] = $attribute->options;
                }

                if (null !== $attribute->defaults) {
                    $globals['defaults'] = $attribute->defaults;
                }

                if (null !== $attribute->schemes) {
                    $globals['schemes'] = $attribute->schemes;
                }

                if (null !== $attribute->methods) {
                    $globals['methods'] = $attribute->methods;
                }

                if (null !== $attribute->host) {
                    $globals['host'] = $attribute->host;
                }

                if (null !== $attribute->condition) {
                    $globals['condition'] = $attribute->condition;
                }

                $globals['priority'] = $attribute->priority ?? 0;
                // a list of environments since Symfony 7.4 (was a single ?string through getEnv())
                $globals['env'] = $attribute->envs;

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

    /**
     * The route's path as a string for the generated route name; empty for a localized (array) path.
     * @param Route $route
     * @return string
     */
    protected function getStringPath(Route $route): string
    {
        return is_string($route->path) ? $route->path : '';
    }
}

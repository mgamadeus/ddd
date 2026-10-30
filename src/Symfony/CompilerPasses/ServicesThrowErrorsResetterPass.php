<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Services\ServicesThrowErrorsResetter;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

/**
 * Wires the reset of `throwErrors` between two units of work for every service definition whose class extends
 * {@see Service} — framework, module and application services alike:
 *  - the {@see ServicesThrowErrorsResetter} receives them as references that yield only instantiated services; the
 *    {@see \DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware} runs it before every consumed message;
 *  - each definition is tagged `kernel.reset` with the method `resetThrowErrorsToDefault`, so Symfony's
 *    `services_resetter` resets the flag after each message and between requests of a worker runtime. A dedicated
 *    method on purpose: services of applications have their own `reset()` with a different meaning.
 *
 * Runs before Symfony's ResettableServicePass (priority -32 of the same phase), after the module services are
 * registered.
 */
class ServicesThrowErrorsResetterPass implements CompilerPassInterface
{
    public const string RESET_METHOD = 'resetThrowErrorsToDefault';

    public function process(ContainerBuilder $container): void
    {
        $serviceReferences = [];
        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if (!$this->isResettableServiceDefinition($container, $serviceId, $definition)) {
                continue;
            }
            $serviceReferences[$serviceId] = new Reference($serviceId, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
            if (!$this->definitionHasResetMethodTag($definition)) {
                $definition->addTag('kernel.reset', ['method' => self::RESET_METHOD]);
            }
        }
        $container->setDefinition(
            ServicesThrowErrorsResetter::class,
            (new Definition(ServicesThrowErrorsResetter::class))
                ->setArgument(0, new IteratorArgument($serviceReferences))
                ->setPublic(true)
        );
    }

    /**
     * A shared, concrete, container-built definition whose class extends {@see Service}.
     */
    protected function isResettableServiceDefinition(ContainerBuilder $container, string $serviceId, Definition $definition): bool
    {
        if ($definition->isAbstract() || $definition->isSynthetic() || !$definition->isShared()) {
            return false;
        }
        try {
            $serviceClass = $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId);
            return is_string($serviceClass) && class_exists($serviceClass) && is_a($serviceClass, Service::class, true);
        } catch (Throwable) {
            return false;
        }
    }

    protected function definitionHasResetMethodTag(Definition $definition): bool
    {
        foreach ($definition->getTag('kernel.reset') as $tagAttributes) {
            if (($tagAttributes['method'] ?? null) === self::RESET_METHOD) {
                return true;
            }
        }
        return false;
    }
}

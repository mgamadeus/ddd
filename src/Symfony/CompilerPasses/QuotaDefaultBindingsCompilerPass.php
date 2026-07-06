<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Infrastructure\Quota\ApcRedisSyncingQuotaConsumer;
use DDD\Infrastructure\Quota\NullQuotaAccountResolver;
use DDD\Infrastructure\Quota\QuotaAccountResolverInterface;
use DDD\Infrastructure\Quota\QuotaConsumerInterface;
use DDD\Infrastructure\Quota\QuotaGuard;
use DDD\Infrastructure\Quota\QuotaKeyResolver;
use DDD\Infrastructure\Quota\QuotaRegistry;
use DDD\Infrastructure\Quota\StoreDispatchingQuotaConsumer;
use DDD\Infrastructure\Quota\SymfonyRateLimiterQuotaConsumer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Makes the generic quota framework ({@see \DDD\Infrastructure\Quota}) INERT-BY-DEFAULT in every DDD app. The
 * {@see \DDD\Symfony\EventListeners\QuotaSubscriber} is auto-registered as an event subscriber, and it (via
 * {@see QuotaGuard}) depends on two ports — {@see QuotaConsumerInterface} and {@see QuotaAccountResolverInterface} —
 * that have no concrete class, so an app that never uses a quota would otherwise fail to COMPILE its container
 * ("Cannot autowire … references interface … but no such service exists"). This pass registers the framework's own
 * default implementations for those ports (and ensures the concrete engine services are autowired), so the container
 * always compiles with zero app-side wiring.
 *
 * It only fills GAPS: a binding the consuming app already declares (e.g. RC aliasing
 * {@see QuotaAccountResolverInterface} to its authenticated-account resolver, or {@see QuotaConsumerInterface} to
 * {@see StoreDispatchingQuotaConsumer}) is left untouched — the app's binding always wins. The default account resolver
 * is {@see NullQuotaAccountResolver} (no account → account-keyed groups fail open; IP / custom quotas still enforce).
 */
class QuotaDefaultBindingsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Ensure the concrete engine services exist as autowired definitions (no-op if the app already registered them
        // through a resource block).
        foreach (
            [
                QuotaRegistry::class,
                QuotaKeyResolver::class,
                SymfonyRateLimiterQuotaConsumer::class,
                ApcRedisSyncingQuotaConsumer::class,
                StoreDispatchingQuotaConsumer::class,
                QuotaGuard::class,
                NullQuotaAccountResolver::class,
            ] as $class
        ) {
            $this->ensureAutowiredDefinition($container, $class);
        }

        // Default port bindings — only when the app has not bound them itself (its binding always wins).
        if (!$container->has(QuotaConsumerInterface::class)) {
            $container->setAlias(QuotaConsumerInterface::class, StoreDispatchingQuotaConsumer::class);
        }
        if (!$container->has(QuotaAccountResolverInterface::class)) {
            $container->setAlias(QuotaAccountResolverInterface::class, NullQuotaAccountResolver::class);
        }
    }

    protected function ensureAutowiredDefinition(ContainerBuilder $container, string $class): void
    {
        if ($container->hasDefinition($class) || $container->hasAlias($class)) {
            return;
        }
        $definition = new Definition($class);
        $definition->setAutowired(true);
        $definition->setAutoconfigured(true);
        $container->setDefinition($class, $definition);
    }
}

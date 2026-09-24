<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Puts the {@see FreshWorkerStateMiddleware} FIRST on every Messenger bus, so every consumed job in every DDD app
 * starts on fresh process state without the app touching its messenger configuration or a single handler.
 *
 * Runs BEFORE Symfony's MessengerPass (same compiler phase, higher priority — see {@see \DDD\Symfony\Kernels\DDDKernel::build()}):
 * the framework extension leaves each bus's middleware list in the parameter `<busId>.middleware`, the MessengerPass
 * consumes and removes it. This pass prepends one entry per bus pointing at a bus-specific service definition that
 * receives the bus's handlers locator (`<busId>.messenger.handlers_locator`, created later by the MessengerPass —
 * the reference resolves at the end of compilation, exactly like the framework's own handle_message middleware).
 *
 * Opt-out for a whole app: parameter `ddd.messenger.fresh_worker_state: false`. Idempotent: a bus that already
 * lists the middleware (an app pinning it by hand) is left alone.
 */
class FreshWorkerStateMiddlewarePass implements CompilerPassInterface
{
    public const string ENABLED_PARAMETER = 'ddd.messenger.fresh_worker_state';
    public const string MIDDLEWARE_ID_SUFFIX = '.middleware.ddd_fresh_worker_state';

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter(self::ENABLED_PARAMETER) && !$container->getParameter(self::ENABLED_PARAMETER)) {
            return;
        }
        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $middlewareParameter = $busId . '.middleware';
            if (!$container->hasParameter($middlewareParameter)) {
                continue;
            }
            /** @var array<int, array{id: string, arguments?: array<int, mixed>}> $middleware */
            $middleware = $container->getParameter($middlewareParameter);
            if ($this->busAlreadyListsTheMiddleware($middleware, $busId)) {
                continue;
            }
            $middlewareId = $busId . self::MIDDLEWARE_ID_SUFFIX;
            $container->setDefinition(
                $middlewareId,
                (new Definition(FreshWorkerStateMiddleware::class))
                    ->setArgument(0, new Reference($busId . '.messenger.handlers_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            );
            array_unshift($middleware, ['id' => $middlewareId]);
            $container->setParameter($middlewareParameter, $middleware);
        }
    }

    /** @param array<int, array{id: string, arguments?: array<int, mixed>}> $middleware */
    protected function busAlreadyListsTheMiddleware(array $middleware, string $busId): bool
    {
        foreach ($middleware as $middlewareItem) {
            $id = $middlewareItem['id'];
            if ($id === $busId . self::MIDDLEWARE_ID_SUFFIX || $id === FreshWorkerStateMiddleware::class) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware;
use DDD\Symfony\Messenger\Middleware\WorkspaceOriginGuardMiddleware;
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
 *
 * The same pass registers the {@see WorkspaceOriginGuardMiddleware} right behind it (a consumed message dispatched by
 * ANOTHER workspace is refused, never run on the wrong code); its own opt-out is `ddd.messenger.workspace_origin_guard: false`.
 */
class FreshWorkerStateMiddlewarePass implements CompilerPassInterface
{
    public const string ENABLED_PARAMETER = 'ddd.messenger.fresh_worker_state';
    public const string MIDDLEWARE_ID_SUFFIX = '.middleware.ddd_fresh_worker_state';
    public const string ORIGIN_GUARD_ENABLED_PARAMETER = 'ddd.messenger.workspace_origin_guard';
    public const string ORIGIN_GUARD_MIDDLEWARE_ID_SUFFIX = '.middleware.ddd_workspace_origin_guard';

    public function process(ContainerBuilder $container): void
    {
        $freshStateEnabled = !($container->hasParameter(self::ENABLED_PARAMETER) && !$container->getParameter(self::ENABLED_PARAMETER));
        $originGuardEnabled = !($container->hasParameter(self::ORIGIN_GUARD_ENABLED_PARAMETER) && !$container->getParameter(self::ORIGIN_GUARD_ENABLED_PARAMETER));
        if (!$freshStateEnabled && !$originGuardEnabled) {
            return;
        }
        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $middlewareParameter = $busId . '.middleware';
            if (!$container->hasParameter($middlewareParameter)) {
                continue;
            }
            /** @var array<int, array{id: string, arguments?: array<int, mixed>}> $middleware */
            $middleware = $container->getParameter($middlewareParameter);
            // Prepend in reverse order so the list starts with fresh state, then the origin guard, then the app's own.
            if ($originGuardEnabled && !$this->busAlreadyListsTheMiddleware($middleware, $busId, self::ORIGIN_GUARD_MIDDLEWARE_ID_SUFFIX, WorkspaceOriginGuardMiddleware::class)) {
                $originGuardId = $busId . self::ORIGIN_GUARD_MIDDLEWARE_ID_SUFFIX;
                $container->setDefinition($originGuardId, new Definition(WorkspaceOriginGuardMiddleware::class));
                array_unshift($middleware, ['id' => $originGuardId]);
            }
            if ($freshStateEnabled && !$this->busAlreadyListsTheMiddleware($middleware, $busId, self::MIDDLEWARE_ID_SUFFIX, FreshWorkerStateMiddleware::class)) {
                $middlewareId = $busId . self::MIDDLEWARE_ID_SUFFIX;
                $container->setDefinition(
                    $middlewareId,
                    (new Definition(FreshWorkerStateMiddleware::class))
                        ->setArgument(0, new Reference($busId . '.messenger.handlers_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE))
                );
                array_unshift($middleware, ['id' => $middlewareId]);
            }
            $container->setParameter($middlewareParameter, $middleware);
        }
    }

    /**
     * @param array<int, array{id: string, arguments?: array<int, mixed>}> $middleware
     * @param class-string $middlewareClass
     */
    protected function busAlreadyListsTheMiddleware(array $middleware, string $busId, string $idSuffix, string $middlewareClass): bool
    {
        foreach ($middleware as $middlewareItem) {
            $id = $middlewareItem['id'];
            if ($id === $busId . $idSuffix || $id === $middlewareClass) {
                return true;
            }
        }
        return false;
    }
}

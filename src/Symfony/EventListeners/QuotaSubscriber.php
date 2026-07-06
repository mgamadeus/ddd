<?php

declare(strict_types=1);

namespace DDD\Symfony\EventListeners;

use Closure;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Quota\Quota;
use DDD\Infrastructure\Quota\QuotaAccountResolverInterface;
use DDD\Infrastructure\Quota\QuotaContext;
use DDD\Infrastructure\Quota\QuotaGuard;
use DDD\Infrastructure\Quota\QuotaOnExceed;
use DDD\Infrastructure\Quota\RequestQuotaFieldReader;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The ONE generic quota enforcer: reads a controller's / action's {@see Quota} attribute and, before the action runs,
 * consumes the limiters the {@see QuotaGuard} maps for its quota type from the `Common.Quota` config — throwing HTTP 429
 * + `Retry-After` on breach. Attribute-driven and config-driven: a new quota category never adds a second subscriber.
 * Method-level attribute overrides class-level.
 *
 * Runs at CONTROLLER priority 20 (before most listeners) so a throttled request is rejected before it does any work.
 * Inert until a controller carries {@see Quota}: no attribute → early return, zero behaviour change. The account key is
 * resolved through the consuming app's {@see QuotaAccountResolverInterface}; the IP key prefers the `X-Real-IP` header
 * (proxy-forwarded client) and falls back to the connection IP.
 */
class QuotaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected readonly QuotaGuard $quotaGuard,
        protected readonly QuotaAccountResolverInterface $accountResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 20],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $quota = $this->getAttributeForController($event->getController());
        if ($quota === null || $quota->disabled) {
            return;
        }

        $request = $event->getRequest();
        $context = $this->buildContext($request);
        $fieldReader = new RequestQuotaFieldReader($request);
        $denial = $this->quotaGuard->check($quota->quotaType, $context, $quota->keyOverride, $fieldReader);
        if ($denial === null) {
            return;
        }

        // Reject the way the breached group asks — a standards-compliant 429 throttle, or (for legacy-parity migrations)
        // a 400 Bad Request with the limiter's own message.
        if ($denial->onExceed === QuotaOnExceed::BAD_REQUEST_400) {
            throw new BadRequestException($denial->message ?? 'Too many requests.');
        }
        throw new TooManyRequestsHttpException(
            $denial->retryAfterSeconds,
            $denial->message ?? ('Rate limit exceeded. Retry after ' . $denial->retryAfterSeconds . ' seconds.'),
        );
    }

    protected function buildContext(Request $request): QuotaContext
    {
        return new QuotaContext(
            accountId: $this->accountResolver->resolveAccountId($request),
            clientIp: $request->headers->get('x-real-ip') ?? $request->getClientIp(),
        );
    }

    /**
     * The {@see Quota} attribute for a controller — method-level takes precedence over class-level.
     *
     * @param callable|array|object $controller
     */
    protected function getAttributeForController(callable|array|object $controller): ?Quota
    {
        $reflectionClass = null;
        $reflectionMethod = null;

        if (is_array($controller)) {
            [$controllerObject, $methodName] = $controller;
            $reflectionClass = new ReflectionClass($controllerObject);
            $reflectionMethod = $reflectionClass->getMethod($methodName);
        } elseif (is_object($controller) && !$controller instanceof Closure) {
            $reflectionClass = new ReflectionClass($controller);
            if ($reflectionClass->hasMethod('__invoke')) {
                $reflectionMethod = $reflectionClass->getMethod('__invoke');
            }
        }

        if ($reflectionMethod !== null) {
            $methodAttribute = $this->getAttributeFromReflection($reflectionMethod);
            if ($methodAttribute !== null) {
                return $methodAttribute;
            }
        }

        if ($reflectionClass !== null) {
            return $this->getAttributeFromReflection($reflectionClass);
        }

        return null;
    }

    protected function getAttributeFromReflection(ReflectionClass|ReflectionMethod $reflection): ?Quota
    {
        $attributes = $reflection->getAttributes(Quota::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return null;
        }

        /** @var Quota $quota */
        $quota = $attributes[0]->newInstance();
        return $quota;
    }
}

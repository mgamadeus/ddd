<?php

declare (strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\Base\Entities\BaseObject;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Presentation\Base\Router\RouteAttributes\RequestCache;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

use function is_array;
use function is_int;
use function is_string;

class RequestCacheSubscriber implements EventSubscriberInterface
{
    protected RouterInterface $router;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 100],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        // Only handle main GET requests
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->getMethod() !== 'GET') {
            return;
        }

        $ctrl = $event->getController();
        // Skip if controller is not a callable array
        if (!is_array($ctrl)) {
            return;
        }

        [$obj, $method] = $ctrl;
        $ref = new ReflectionMethod($obj, $method);
        $attrs = $ref->getAttributes(RequestCache::class);
        if (empty($attrs)) {
            return;
        }

        /** @var RequestCache $requestCacheAttributeInstance */
        $requestCacheAttributeInstance = $attrs[0]->newInstance();
        $ttl = $requestCacheAttributeInstance->ttl;
        $key = $this->makeCacheKey($request, $requestCacheAttributeInstance);

        // Check for 'noCache' query parameter: skip cache read when true
        $skipRead = filter_var($request->query->get('noCache'), FILTER_VALIDATE_BOOLEAN);
        if (!$skipRead) {
            $cachedResponse = Cache::instance()->get($key);
            if ($cachedResponse && $cachedResponse instanceof Response) {
                // lege Controller auf Closure, die den gespeicherten Response liefert
                $event->setController(fn() => $cachedResponse);
                return;
            }
        }
        // Mark request for caching after controller execution
        $request->attributes->set('_request_cache_ttl', $ttl);
        $request->attributes->set('_request_cache_key', $key);
    }

    public function onResponse(ResponseEvent $event): void
    {
        // Only cache main GET responses
        $req = $event->getRequest();
        if (!$event->isMainRequest() || $req->getMethod() !== 'GET') {
            return;
        }

        $ttl = $req->attributes->get('_request_cache_ttl');
        $key = $req->attributes->get('_request_cache_key');
        // No caching info? Skip.
        if (!is_int($ttl) || $ttl <= 0 || !is_string($key)) {
            return;
        }

        $response = $event->getResponse();
        // Only cache successful (2xx) responses
        if (!$response->isSuccessful()) {
            return;
        }
        // We avoid saving complex data to be serialized and unset complex properties
        $responseReflection = ReflectionClass::instance($response::class);

        // Trigger storage of Content
        $response->getContent();

        // Unset complex properties
        foreach ($responseReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isInitialized($response)) {
                continue;
            }
            if ($property->getType() instanceof \ReflectionNamedType) {
                if (is_a($property->getType()->getName(), BaseObject::class, true)) {
                    unset($response->{$property->getName()});
                }
            } elseif ($property->getType() instanceof \ReflectionUnionType) {
                $types = $property->getType()->getTypes();
                foreach ($types as $type) {
                    if (is_a($type->getName(), BaseObject::class, true)) {
                        unset($response->{$property->getName()});
                    }
                }
            }
        }
        Cache::instance()->set($key, $response, $ttl);
    }

    private function makeCacheKey(Request $req, RequestCache $requestCacheAttributeInstance): string
    {
        $method = $req->getMethod();
        $path = $req->getPathInfo();

        // Include sorted query parameters in the key
        $qs = $req->query->all();
        if ($qs) {
            ksort($qs);
            $path .= '?' . http_build_query($qs);
        }

        // Append whitelisted header values to the key
        if ($requestCacheAttributeInstance->headersToConsiderForCacheKey) {
            $vals = [];
            foreach ($requestCacheAttributeInstance->headersToConsiderForCacheKey as $header) {
                if ($req->headers->has($header)) {
                    $vals[$header] = $req->headers->get($header);
                }
            }
            if ($vals) {
                ksort($vals);
                $path .= '|hdr:' . http_build_query($vals, '', ';');
            }
        }
        if ($requestCacheAttributeInstance->considerCurrentAuthAccountForCacheKey) {
            if ($authAccount = AuthService::instance()->getAccount()) {
                $path .= '|account:' . $authAccount->id;
            }
        }
        // Use a hash to ensure a safe cache key
        return 'req_cache_' . md5($method . '_' . $path);
    }
}
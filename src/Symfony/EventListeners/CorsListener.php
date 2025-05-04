<?php

declare (strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CorsListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9999],
            KernelEvents::RESPONSE => ['onKernelResponse', 9999],
            KernelEvents::EXCEPTION => ['onKernelException', 9999],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof AccessDeniedException) {
            $exception = new ForbiddenException('Insufficient permissions to access endpoint');
            $response = new RestResponseDto($exception->toJSON(), $exception->getCode(), [], true);
            $event->setResponse($response);
        }
        $response = $event->getResponse();
        if ($response) {
            $origin = $event->getRequest()->headers->get('Origin') ?: '*';
            $allowed = $event->getRequest()->headers->get('Access-Control-Request-Headers', '*');
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Headers', $allowed);
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Don't do anything if it's not the master request.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getRealMethod();

        if (Request::METHOD_OPTIONS === $method) {
            $response = new Response();
            $origin = $request->headers->get('Origin') ?: '*';
            $requestedHeaders = $request->headers->get('Access-Control-Request-Headers', '*');
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Headers', $requestedHeaders);
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Don't do anything if it's not the master request.
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if ($response) {
            $origin = $event->getRequest()->headers->get('Origin') ?: '*';
            $allowed = $event->getRequest()->headers->get('Access-Control-Request-Headers', '*');
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Headers', $allowed);
        }
    }
}
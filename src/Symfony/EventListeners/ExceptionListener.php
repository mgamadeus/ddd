<?php

declare(strict_types=1);

namespace DDD\Symfony\EventListeners;

use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[AsEventListener(event: 'kernel.exception', method: 'onKernelException')]
class ExceptionListener
{
    public function onKernelException(ExceptionEvent $event)
    {
        // Custom Exceptions are returned as JSON
        $exception = $event->getThrowable();
        if ($exception instanceof Exception) {
            $response = new RestResponseDto($exception->toJSON(), $exception->getCode(), [], true);
            $event->setResponse($response);
            return;
        }
        if (($exception->getPrevious() instanceof AuthenticationException || $exception instanceof AccessDeniedException)
            && str_starts_with(
                $event->getRequest()->getRequestUri(),
                '/api'
            )) {
            $exception = new UnauthorizedException('Unauthorized');
            $response = new RestResponseDto($exception->toJSON(), $exception->getCode(), [], true);
            $event->setResponse($response);
            return;
        }
    }
}
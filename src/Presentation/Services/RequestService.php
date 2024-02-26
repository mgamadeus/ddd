<?php

declare(strict_types=1);

namespace DDD\Presentation\Services;

use DDD\Infrastructure\Services\Service;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

class RequestService extends Service
{
    public function __construct(protected RequestStack $requestStack, protected RouterInterface $router)
    {
    }

    /**
     * @return RequestStack
     */
    public function getRequestStack(): RequestStack
    {
        return $this->requestStack;
    }

    /**
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }
}
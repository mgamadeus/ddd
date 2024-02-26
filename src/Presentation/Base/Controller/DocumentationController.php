<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Controller;

use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Routing\RouteCollection;

class DocumentationController extends HttpController
{
    protected function getRouteCollection():RouteCollection {
        /** @var Router $router */
        $router = $this->container->get('router');
        $routeCollection = $router->getRouteCollection();
        return $routeCollection;
    }
}

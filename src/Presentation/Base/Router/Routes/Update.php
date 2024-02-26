<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Router\Routes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\HttpFoundation\Request;

#[Attribute]
class Update extends Route
{
    use BaseAttributeTrait;

    public function __construct(
        array|string $path = null,
        ?string $name = null,
        array $requirements = [],
        array $options = [],
        array $defaults = [],
        ?string $host = null,
        array|string $methods = [],
        array|string $schemes = [],
        ?string $condition = null,
        ?int $priority = null,
        string $locale = null,
        string $format = null,
        bool $utf8 = null,
        bool $stateless = null,
        ?string $env = null
    ) {
        $methods = [Request::METHOD_PATCH, Request::METHOD_POST];
        parent::__construct(
            $path,
            $name,
            $requirements,
            $options,
            $defaults,
            $host,
            $methods,
            $schemes,
            $condition,
            $priority,
            $locale,
            $format,
            $utf8,
            $stateless,
            $env
        );
    }
}
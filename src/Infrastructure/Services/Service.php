<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

class Service
{
    /** @var bool If true, the service itself will throw errors */
    public bool $throwErrors = false;
}
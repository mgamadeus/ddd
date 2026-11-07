<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/** A module service that subscribes to events and resets its state */
class ResettingModuleSubscriber implements EventSubscriberInterface, ResetInterface
{
    public static function getSubscribedEvents(): array
    {
        return [];
    }

    public function reset(): void
    {
    }
}

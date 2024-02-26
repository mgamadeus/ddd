<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Infrastructure\Services\AuthService;

abstract class AppMessageHandler
{
    protected function setAuthAccountFromMessage(AppMessage $appMessage): void
    {
        if ($appMessage->accountId ?? null) {
            AuthService::instance()->setAccountId($appMessage->accountId);
        }
    }
}
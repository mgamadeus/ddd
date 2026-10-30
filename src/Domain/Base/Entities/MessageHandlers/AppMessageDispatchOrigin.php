<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Where and when an {@see AppMessage} was dispatched, stamped by {@see AppMessage::dispatch()}: a failing job's issues
 * event carries it (`messenger.origin.*`) and the queue delay (`messenger.age_seconds`). Part of the message body, so
 * it survives every transport serializer (stamps do not survive AppMessage's own JSON serializer).
 */
class AppMessageDispatchOrigin extends ValueObject
{
    /** @var DateTime|null When the message was dispatched */
    public ?DateTime $dispatchedAt = null;

    /** @var string|null The route of the request that dispatched the message */
    public ?string $route = null;

    /** @var string|null The console command that dispatched the message, e.g. `messenger:consume` for a worker */
    public ?string $command = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}

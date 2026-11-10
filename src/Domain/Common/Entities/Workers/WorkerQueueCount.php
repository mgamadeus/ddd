<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * The messages waiting in one transport's queue: ready, not yet delivered to a worker. Messages in work are not in
 * it — those come from the worker processes' heartbeats.
 */
class WorkerQueueCount extends ValueObject
{
    /** The transport answered with a number */
    public const string STATE_COUNTED = 'COUNTED';
    /** The transport cannot count, or asking it failed */
    public const string STATE_UNKNOWN = 'UNKNOWN';
    /** The queue does not exist yet: nothing was dispatched or consumed since the broker was set up */
    public const string STATE_QUEUE_NOT_SET_UP = 'QUEUE_NOT_SET_UP';

    public function __construct(
        public string $transport = '',
        #[Choice(choices: [self::STATE_COUNTED, self::STATE_UNKNOWN, self::STATE_QUEUE_NOT_SET_UP])]
        public string $state = self::STATE_UNKNOWN,
        /** @var int|null Waiting messages; null unless the state is COUNTED */
        public ?int $waiting = null,
        /** @var string|null Why the count is unknown */
        public ?string $error = null,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->transport);
    }
}

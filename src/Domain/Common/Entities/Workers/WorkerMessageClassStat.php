<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What the workers of a transport did with one message class in a window.
 */
class WorkerMessageClassStat extends ValueObject
{
    public string $messageClass = '';

    /** @var int Messages that finished (whatever the outcome) */
    public int $handledCount = 0;

    /** @var int Messages whose handler threw */
    public int $thrownCount = 0;

    /** @var int Messages whose handler logged an error and returned */
    public int $caughtCount = 0;

    /** @var int Messages that were a retry */
    public int $redeliveredCount = 0;

    /** @var int|null Average handling time; null without a message */
    public ?int $durationAverageMs = null;

    public int $durationMaxMs = 0;

    /** @var int|null Average time between dispatch and start; null when no message carried its dispatch time */
    public ?int $waitAverageMs = null;

    public ?int $waitMaxMs = null;

    public ?string $lastErrorClass = null;

    public ?string $lastErrorMessage = null;

    public ?DateTime $lastErrorAt = null;

    public function __construct(string $messageClass = '')
    {
        $this->messageClass = $messageClass;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->messageClass);
    }

    public function getFailedCount(): int
    {
        return $this->thrownCount + $this->caughtCount;
    }
}

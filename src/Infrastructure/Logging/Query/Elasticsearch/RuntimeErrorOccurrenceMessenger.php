<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The worker fields of an occurrence of a failed Messenger job.
 */
class RuntimeErrorOccurrenceMessenger extends ValueObject
{
    /** The message class (`messenger.message_class`) */
    public ?string $messageClass = null;

    /** The failing handler, `Class::__invoke` (`messenger.handler`) */
    public ?string $handler = null;

    /** What the message is about: its scalar public properties, values redacted (`messenger.message_keys`) */
    public RuntimeErrorKeyValues $messageKeys;

    /** The delivery that failed, 1 = the first (`messenger.attempt`) */
    public ?int $attempt = null;

    /** Redeliveries before this one (`messenger.retry_count`) */
    public ?int $retryCount = null;

    /** True when no retry follows: the message went to the failure transport (`messenger.final`) */
    public ?bool $final = null;

    /** Whether Messenger retries the message (`will_retry`) */
    public ?bool $willRetry = null;

    /** @var string[] The transports the message came from (`transports`) */
    public array $transports = [];

    /** The route of the request that dispatched the message, redacted (`messenger.origin.route`) */
    public ?string $originRoute = null;

    /** The console command that dispatched the message (`messenger.origin.command`) */
    public ?string $originCommand = null;

    /** The account the message was dispatched for (`messenger.origin.account_id`) */
    public ?int $originAccountId = null;

    /** The workspace the message was dispatched from (`messenger.origin.workspace`) */
    public ?string $originWorkspace = null;

    /** When the message was dispatched, RFC 3339 as logged (`messenger.origin.dispatched_at`) */
    public ?string $originDispatchedAt = null;

    /** Seconds between dispatch and the failing handling: the queue delay (`messenger.age_seconds`) */
    public ?int $ageSeconds = null;

    public function __construct()
    {
        $this->messageKeys = new RuntimeErrorKeyValues();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}

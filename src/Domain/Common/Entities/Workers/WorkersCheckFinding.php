<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One result of the worker check: a mismatch between the worker list and the Messenger configuration.
 */
class WorkersCheckFinding extends ValueObject
{
    public const string SEVERITY_ERROR = 'ERROR';
    public const string SEVERITY_WARNING = 'WARNING';

    public const string CODE_DEFINITION_FILE_MISSING = 'DEFINITION_FILE_MISSING';
    /** A transport messages are routed to has no consumer entry: its messages would wait forever */
    public const string CODE_TRANSPORT_WITHOUT_CONSUMER = 'TRANSPORT_WITHOUT_CONSUMER';
    public const string CODE_CONSUMER_WITHOUT_TRANSPORT = 'CONSUMER_WITHOUT_TRANSPORT';
    public const string CODE_CONSUMER_OF_SYNC_TRANSPORT = 'CONSUMER_OF_SYNC_TRANSPORT';
    public const string CODE_DUPLICATE_CONSUMER = 'DUPLICATE_CONSUMER';
    public const string CODE_MIN_ABOVE_MAX = 'MIN_ABOVE_MAX';
    public const string CODE_INVALID_VALUE = 'INVALID_VALUE';
    public const string CODE_ROUTED_TO_UNKNOWN_TRANSPORT = 'ROUTED_TO_UNKNOWN_TRANSPORT';
    public const string CODE_ROUTED_CLASS_MISSING = 'ROUTED_CLASS_MISSING';
    /** The monitoring is on, but the sampler command wrote no recent sample: the queue history has holes */
    public const string CODE_SAMPLER_NOT_RUNNING = 'SAMPLER_NOT_RUNNING';
    public const string CODE_MONITORING_TABLES_MISSING = 'MONITORING_TABLES_MISSING';

    public function __construct(
        public string $severity = self::SEVERITY_ERROR,
        public string $code = '',
        public string $message = '',
        /** @var string|null The transport the finding is about */
        public ?string $transport = null,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}

<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\ErrorFrames\IssueErrorFrames;

/**
 * One link of an occurrence's `error.causes` (the getPrevious() chain), outermost first.
 */
class RuntimeErrorCause extends ValueObject
{
    /** The exception class as logged (FQCN) */
    public ?string $errorType = null;

    /** The cause's message, redacted */
    public ?string $message = null;

    /** Where the cause was thrown, `<relative file>:<line>` as logged */
    public ?string $throwSite = null;

    /** The cause's first frames (at most 3), innermost first */
    public IssueErrorFrames $errorFrames;

    public function __construct()
    {
        $this->errorFrames = new IssueErrorFrames();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}

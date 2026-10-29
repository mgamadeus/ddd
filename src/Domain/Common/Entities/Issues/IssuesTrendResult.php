<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;

/**
 * A trend summed over several environments, with the environments whose backend failed (their events are missing from
 * the trend).
 */
class IssuesTrendResult extends ValueObject
{
    public DateTimeValueSequence $trend;

    /** @var IssuesEnvironmentFailures The environments not in the trend because their backend failed */
    public IssuesEnvironmentFailures $failedEnvironments;

    public function __construct()
    {
        $this->failedEnvironments = new IssuesEnvironmentFailures();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}

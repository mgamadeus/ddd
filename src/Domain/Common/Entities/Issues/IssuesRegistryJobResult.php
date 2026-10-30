<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one run of the registry job did.
 */
class IssuesRegistryJobResult extends ValueObject
{
    /** @var string|null Why the run did nothing (no backend, another run holds the lock) */
    public ?string $skippedReason = null;

    public int $groupsCreated = 0;

    public int $issuesCreated = 0;

    public int $regressions = 0;

    public int $eventsCounted = 0;

    public int $alertsSent = 0;

    public int $alertsFailed = 0;

    public bool $seedMode = false;

    public bool $explosion = false;

    /** @var string[] The environments the run went through */
    public array $environments = [];

    /** @var string[] The environments in seed mode */
    public array $environmentsInSeedMode = [];

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}

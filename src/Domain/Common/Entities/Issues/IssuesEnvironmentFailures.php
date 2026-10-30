<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The environments that failed while a command read several.
 *
 * @property IssuesEnvironmentFailure[] $elements
 * @method IssuesEnvironmentFailure|null first()
 * @method IssuesEnvironmentFailure[] getElements()
 */
class IssuesEnvironmentFailures extends ObjectSet
{
    public function addFailure(string $environment, string $error): void
    {
        $issuesEnvironmentFailure = new IssuesEnvironmentFailure();
        $issuesEnvironmentFailure->environment = $environment;
        $issuesEnvironmentFailure->error = $error;
        $this->add($issuesEnvironmentFailure);
    }
}

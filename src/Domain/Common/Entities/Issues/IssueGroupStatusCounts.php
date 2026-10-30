<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Group counts per environment and status.
 *
 * @property IssueGroupStatusCount[] $elements;
 * @method IssueGroupStatusCount[] getElements()
 * @method IssueGroupStatusCount|null getByUniqueKey(string $uniqueKey)
 */
class IssueGroupStatusCounts extends ObjectSet
{
    /** The count of one status in one environment (null = summed over all environments) */
    public function getCount(?string $environment, string $status): int
    {
        $count = 0;
        foreach ($this->getElements() as $issueGroupStatusCount) {
            if ($issueGroupStatusCount->status === $status && ($environment === null || $issueGroupStatusCount->environment === $environment)) {
                $count += $issueGroupStatusCount->count;
            }
        }
        return $count;
    }

    /** @return string[] The environments with groups */
    public function getEnvironments(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(IssueGroupStatusCount $issueGroupStatusCount): ?string => $issueGroupStatusCount->environment,
            $this->getElements()
        ), static fn(?string $environment): bool => $environment !== null)));
    }
}

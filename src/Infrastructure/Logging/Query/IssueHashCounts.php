<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property IssueHashCount[] $elements;
 * @method IssueHashCount[] getElements()
 */
class IssueHashCounts extends ObjectSet
{
    /** @return string[] The distinct group hashes */
    public function getGroupHashes(): array
    {
        return array_values(array_unique(array_map(
            static fn(IssueHashCount $issueHashCount): string => $issueHashCount->groupHash,
            $this->getElements()
        )));
    }

    public function getCountForGroupHash(string $groupHash): int
    {
        $count = 0;
        foreach ($this->getElements() as $issueHashCount) {
            if ($issueHashCount->groupHash === $groupHash) {
                $count += $issueHashCount->count;
            }
        }
        return $count;
    }
}

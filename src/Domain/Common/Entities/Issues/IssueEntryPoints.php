<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property IssueEntryPoint[] $elements;
 * @method IssueEntryPoint[] getElements()
 */
class IssueEntryPoints extends ObjectSet
{
    /** @return string[] */
    public function getNames(): array
    {
        return array_map(static fn(IssueEntryPoint $entryPoint): string => $entryPoint->name, $this->getElements());
    }
}

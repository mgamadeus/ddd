<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The frames of a client error after source-map resolution, innermost first.
 *
 * @property ResolvedFrame[] $elements
 * @method ResolvedFrame|null first()
 * @method ResolvedFrame[] getElements()
 */
class ResolvedFrames extends ObjectSet
{
    /** @return string[] One `at function (file:line:column)` per frame */
    public function toStackTraceLines(): array
    {
        return array_map(static fn(ResolvedFrame $resolvedFrame): string => $resolvedFrame->toStackTraceLine(), array_values($this->getElements()));
    }
}

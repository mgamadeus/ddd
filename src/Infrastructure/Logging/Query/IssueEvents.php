<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property IssueEvent[] $elements;
 * @method IssueEvent[] getElements()
 * @method IssueEvent|null first()
 */
class IssueEvents extends ObjectSet
{
}

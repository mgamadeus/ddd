<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The events of one client report (1 to {@see self::MAX_EVENTS}).
 *
 * @property ClientIssueEvent[] $elements
 * @method ClientIssueEvent|null first()
 * @method ClientIssueEvent[] getElements()
 */
class ClientIssueEvents extends ObjectSet
{
    public const int MAX_EVENTS = 10;
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tags of a client error, one per key (at most {@see self::MAX_TAGS}).
 *
 * @property ClientIssueTag[] $elements
 * @method ClientIssueTag|null first()
 * @method ClientIssueTag[] getElements()
 */
class ClientIssueTags extends ObjectSet
{
    public const int MAX_TAGS = 10;
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\ErrorFrames;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The frames of a backend error, innermost (the throw site) first.
 *
 * @property IssueErrorFrame[] $elements
 * @method IssueErrorFrame|null first()
 * @method IssueErrorFrame[] getElements()
 */
class IssueErrorFrames extends ObjectSet
{
}

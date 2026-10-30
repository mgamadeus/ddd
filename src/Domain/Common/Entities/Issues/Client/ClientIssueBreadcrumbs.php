<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The breadcrumbs of a client error, oldest first (at most {@see self::MAX_BREADCRUMBS}, the newest are kept).
 *
 * @property ClientIssueBreadcrumb[] $elements
 * @method ClientIssueBreadcrumb|null first()
 * @method ClientIssueBreadcrumb[] getElements()
 */
class ClientIssueBreadcrumbs extends ObjectSet
{
    public const int MAX_BREADCRUMBS = 20;
}

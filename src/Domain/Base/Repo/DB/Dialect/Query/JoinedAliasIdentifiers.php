<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * {@see JoinedAliasIdentifier}s in join order (parent before child), as {@see DefaultOrderTreeWalker} appends their
 * tie-breakers.
 *
 * @property JoinedAliasIdentifier[] $elements
 * @method JoinedAliasIdentifier|null first()
 * @method JoinedAliasIdentifier[] getElements()
 */
class JoinedAliasIdentifiers extends ObjectSet
{
}

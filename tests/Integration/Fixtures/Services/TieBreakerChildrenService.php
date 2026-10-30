<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;

/**
 * @method TieBreakerChild|null find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 */
class TieBreakerChildrenService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = TieBreakerChild::class;
}

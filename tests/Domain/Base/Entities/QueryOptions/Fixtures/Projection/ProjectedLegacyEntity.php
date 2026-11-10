<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsProjection;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Base\DateTime\Date;

/**
 * The old entity without a table: `title` was renamed to `headline`; the target's own `title` is therefore not
 * exposed, `onlyOnTarget` is not declared here and `onlyOnLegacy` has no target property
 */
#[QueryOptionsProjection(targetClass: ProjectionTargetEntity::class, propertyMap: ['title' => 'headline'])]
class ProjectedLegacyEntity extends Entity
{
    use QueryOptionsTrait;

    public ?string $title = null;

    public ?int $amount = null;

    public ?Date $dueOn = null;

    public ?string $onlyOnLegacy = null;
}

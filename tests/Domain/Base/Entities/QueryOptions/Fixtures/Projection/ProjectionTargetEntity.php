<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecution;
use DDD\Infrastructure\Base\DateTime\Date;

/** The renamed entity with a table: the target of a QueryOptionsProjection */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCronExecution::class)]
class ProjectionTargetEntity extends Entity
{
    use QueryOptionsTrait;

    public ?string $headline = null;

    public ?int $amount = null;

    public ?Date $dueOn = null;

    public ?string $title = null;

    public ?string $onlyOnTarget = null;
}

<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * An entity with one lazy property read through the recording repository.
 */
class LazyLoadSourceWithRecordedTarget extends Entity
{
    #[LazyLoad(repoType: LazyLoadRepo::VIRTUAL)]
    public ?RecordedLazyLoadTarget $recordedTarget;
}

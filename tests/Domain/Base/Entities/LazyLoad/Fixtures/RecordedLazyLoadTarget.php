<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * A lazy-load target read through the recording repository.
 */
#[LazyLoadRepo(LazyLoadRepo::VIRTUAL, RecordingLazyLoadRepo::class)]
class RecordedLazyLoadTarget extends Entity
{
}

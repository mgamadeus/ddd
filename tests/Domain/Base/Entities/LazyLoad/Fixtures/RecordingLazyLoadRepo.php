<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;

/**
 * A repository that records the caching decision each lazy load hands it and loads nothing.
 */
class RecordingLazyLoadRepo
{
    /** @var bool[] useCache of every load, in order */
    public static array $recordedUseCacheValues = [];

    public function lazyload(DefaultObject $initiatingEntity, LazyLoad $lazyloadAttributeInstance): mixed
    {
        static::$recordedUseCacheValues[] = $lazyloadAttributeInstance->useCache;
        return null;
    }
}

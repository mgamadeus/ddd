<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\LazyLoad;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\LazyLoadSourceWithRecordedTarget;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\RecordedLazyLoadTarget;
use DDD\Tests\Domain\Base\Entities\LazyLoad\Fixtures\RecordingLazyLoadRepo;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The caching decision of a lazy load holds for that load only: a load while caches are off must not switch caching
 * off for every later load of the property in the process (the LazyLoad attribute instance is shared through
 * StaticRegistry for the process lifetime, e.g. a Messenger worker).
 */
class LazyLoadCachingDecisionPerLoadTest extends TestCase
{
    protected bool $noCacheBefore;

    protected ?string $defaultRepoTypeBefore;

    protected function setUp(): void
    {
        $defaultRepoTypeProperty = new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType');
        $this->defaultRepoTypeBefore = $defaultRepoTypeProperty->isInitialized() ? LazyLoadRepo::$defaultRepoType : null;
        // no environment in unit tests: the application's default repo type is DB
        LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        $this->noCacheBefore = DDDService::$noCache;
        RecordingLazyLoadRepo::$recordedUseCacheValues = [];
        $this->clearLazyLoadRegistries();
    }

    protected function tearDown(): void
    {
        LazyLoadRepo::setDafaultRepoType($this->defaultRepoTypeBefore ?? LazyLoadRepo::DB);
        DDDService::$noCache = $this->noCacheBefore;
        $this->clearLazyLoadRegistries();
    }

    protected function clearLazyLoadRegistries(): void
    {
        foreach ([LazyLoadSourceWithRecordedTarget::class, RecordedLazyLoadTarget::class] as $className) {
            unset(
                StaticRegistry::$propertiesToLazyLoadForClasses[$className],
                StaticRegistry::$repoTypesForClasses[$className],
                StaticRegistry::$defaultRepoTypesForClasses[$className],
                StaticRegistry::$repoClassesForProperties[$className]
            );
        }
    }

    public function testALoadWithCachesOffLeavesCachingOnForLaterLoads(): void
    {
        DDDService::$noCache = true;
        (new LazyLoadSourceWithRecordedTarget())->recordedTarget;
        DDDService::$noCache = false;
        (new LazyLoadSourceWithRecordedTarget())->recordedTarget;

        $this->assertSame([false, true], RecordingLazyLoadRepo::$recordedUseCacheValues);
        $this->assertTrue(
            LazyLoadSourceWithRecordedTarget::getPropertiesToLazyLoad()['recordedTarget'][LazyLoadRepo::VIRTUAL]->useCache,
            'the shared attribute instance keeps its declared value'
        );
    }
}

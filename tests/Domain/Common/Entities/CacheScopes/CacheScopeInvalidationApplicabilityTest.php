<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\CacheScopes;

use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidation;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Whether a cache-scope invalidation still applies, as the database queries decide it: a row with a count is
 * count-based — at 0 it no longer applies, even with an unexpired invalidateUntil (such a row would otherwise make
 * every consuming check of a process return false until its list is reset); a row with only invalidateUntil applies
 * until then.
 */
class CacheScopeInvalidationApplicabilityTest extends TestCase
{
    protected function createInvalidation(?int $numberOfTimesToInvalidateCache, ?string $invalidateUntil): CacheScopeInvalidation
    {
        /** @var CacheScopeInvalidation $cacheScopeInvalidation */
        $cacheScopeInvalidation = (new ReflectionClass(CacheScopeInvalidation::class))->newInstanceWithoutConstructor();
        $cacheScopeInvalidation->numberOfTimesToInvalidateCache = $numberOfTimesToInvalidateCache;
        $cacheScopeInvalidation->invalidateUntil = $invalidateUntil !== null ? new DateTime($invalidateUntil) : null;
        return $cacheScopeInvalidation;
    }

    public function testACountRowAtZeroNoLongerAppliesEvenWithAnUnexpiredInvalidateUntil(): void
    {
        $usedUpCountRow = $this->createInvalidation(0, '+1 day');
        $this->assertFalse($usedUpCountRow->isApplicable());
        $this->assertFalse($usedUpCountRow->hasUnexpiredInvalidateUntil());
        $this->assertTrue($usedUpCountRow->isCountUsedUp());
    }

    public function testACountRowAppliesWhileCountAndWindowLast(): void
    {
        $this->assertTrue($this->createInvalidation(2, '+1 day')->isApplicable());
        $this->assertTrue($this->createInvalidation(2, null)->isApplicable());
        $this->assertFalse($this->createInvalidation(2, '-1 hour')->isApplicable(), 'its window is over');
    }

    public function testATimeLimitedRowAppliesUntilItsEnd(): void
    {
        $this->assertTrue($this->createInvalidation(null, '+1 hour')->isApplicable());
        $this->assertTrue($this->createInvalidation(null, '+1 hour')->hasUnexpiredInvalidateUntil());
        $this->assertFalse($this->createInvalidation(null, '-1 hour')->isApplicable());
        $this->assertFalse($this->createInvalidation(null, null)->isApplicable());
    }
}

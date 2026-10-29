<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Infrastructure\Libs\ClassFinder;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

/** An application service that adds its own cache switch through the two state methods. */
class CacheSwitchAddingDDDService extends DDDService
{
    public static bool $applicationCacheOff = false;

    protected function getCurrentCacheStates(): array
    {
        return parent::getCurrentCacheStates() + ['application' => !self::$applicationCacheOff];
    }

    protected function applyCacheStates(array $cacheStates): void
    {
        parent::applyCacheStates($cacheStates);
        self::$applicationCacheOff = !($cacheStates['application'] ?? true);
    }

    public function deactivateCaches(): void
    {
        parent::deactivateCaches();
        self::$applicationCacheOff = true;
    }
}

/**
 * The caches snapshot is a stack: a deactivateCaches() / restoreCachesSnapshot() pair nested inside another one used to
 * overwrite the single slot, so the outer restore found nothing and every cache stayed off for the rest of the process.
 */
class DDDServiceCachesSnapshotStackTest extends TestCase
{
    protected function setUp(): void
    {
        DDDService::instance()->resetCachesStateForNewJob();
    }

    protected function tearDown(): void
    {
        DDDService::instance()->resetCachesStateForNewJob();
        CacheSwitchAddingDDDService::$applicationCacheOff = false;
    }

    protected function assertCachesOn(bool $expectedOn, string $message = ''): void
    {
        $this->assertSame(!$expectedOn, DDDService::$noCache, $message);
        $this->assertSame(!$expectedOn, DoctrineEntityRegistry::$clearCache, $message);
        $this->assertSame(!$expectedOn, VirtualEntityRegistry::$clearCache, $message);
        $this->assertSame(!$expectedOn, ClassFinder::$clearCache, $message);
    }

    public function testANestedPairKeepsTheOuterWindowOffAndEndsWithCachesOn(): void
    {
        $dddService = DDDService::instance();

        $dddService->deactivateCaches();
        $dddService->deactivateCaches();
        $dddService->restoreCachesSnapshot();
        $this->assertCachesOn(false, 'still inside the outer window');
        $dddService->restoreCachesSnapshot();

        $this->assertCachesOn(true, 'the outer restore brings the caches back');
    }

    public function testAnActivatePairInsideADeactivatedWindowRestoresTheWindow(): void
    {
        $dddService = DDDService::instance();

        $dddService->deactivateCaches();
        $dddService->activateCaches();
        $this->assertCachesOn(true);
        $dddService->restoreCachesSnapshot();
        $this->assertCachesOn(false, 'back in the deactivated window');
        $dddService->restoreCachesSnapshot();

        $this->assertCachesOn(true);
    }

    public function testARestoreWithoutSnapshotChangesNothing(): void
    {
        DDDService::$noCache = true;

        DDDService::instance()->restoreCachesSnapshot();

        $this->assertTrue(DDDService::$noCache);
        DDDService::$noCache = false;
    }

    public function testTheJobResetDropsUnrestoredFrames(): void
    {
        $dddService = DDDService::instance();
        $dddService->deactivateCaches();
        $dddService->deactivateCaches();

        $dddService->resetCachesStateForNewJob();
        $this->assertCachesOn(true);
        // a frame of the died job must not be re-applied by a later restore
        DDDService::$noCache = true;
        $dddService->restoreCachesSnapshot();

        $this->assertTrue(DDDService::$noCache);
        DDDService::$noCache = false;
    }

    public function testAnApplicationCacheSwitchIsSnapshottedAndRestoredThroughANestedPair(): void
    {
        $applicationService = new CacheSwitchAddingDDDService();

        $applicationService->deactivateCaches();
        $applicationService->deactivateCaches();
        $applicationService->restoreCachesSnapshot();
        $this->assertTrue(CacheSwitchAddingDDDService::$applicationCacheOff, 'still inside the outer window');
        $applicationService->restoreCachesSnapshot();

        $this->assertFalse(CacheSwitchAddingDDDService::$applicationCacheOff);
        $this->assertCachesOn(true);

        $applicationService->deactivateCaches();
        $applicationService->resetCachesStateForNewJob();
        $this->assertFalse(CacheSwitchAddingDDDService::$applicationCacheOff, 'the job reset switches the added cache on too');
    }
}

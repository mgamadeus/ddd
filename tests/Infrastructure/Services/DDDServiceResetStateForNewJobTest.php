<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Infrastructure\Libs\ClassFinder;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Both resets model the same failure: a worker job that died INSIDE a deactivate/restore pair. Without the reset its
 * snapshot frame and its deactivated state are inherited by every later job of the long-lived process.
 */
class DDDServiceResetStateForNewJobTest extends TestCase
{
    protected function tearDown(): void
    {
        // the statics are process-wide; leave the process on the defaults a fresh job expects
        DDDService::instance()->resetEntityRightsRestrictionsStateForNewJob();
        DDDService::instance()->resetCachesStateForNewJob();
    }

    protected function protectedStatic(string $propertyName): mixed
    {
        $reflectionProperty = new ReflectionProperty(DDDService::class, $propertyName);
        return $reflectionProperty->getValue();
    }

    /** A job that deactivated rights and never restored leaves rights OFF and a frame on the stack. */
    public function testTheRightsRestrictionStateOfADiedJobDoesNotSurviveTheReset(): void
    {
        $dddService = DDDService::instance();
        $dddService->deactivateEntityRightsRestrictions();

        $this->assertFalse(DBEntity::getApplyRightsRestrictions(), 'precondition: the died job left rights off');
        $this->assertNotSame([], $this->protectedStatic('entityRightsRestrictionsStateStack'));

        $dddService->resetEntityRightsRestrictionsStateForNewJob();

        $this->assertTrue(DBEntity::getApplyRightsRestrictions());
        $this->assertSame([], $this->protectedStatic('entityRightsRestrictionsStateStack'));
        $this->assertFalse($this->protectedStatic('entityRightsRestrictionSnapshotSet'));
        $this->assertSame([DBEntity::class => true], $this->protectedStatic('entityRightsRestrictionStates'));
    }

    /** A job that deactivated the caches and never restored leaves every later job of the process without them. */
    public function testTheCacheStateOfADiedJobDoesNotSurviveTheReset(): void
    {
        $dddService = DDDService::instance();
        $dddService->deactivateCaches();

        $this->assertTrue(DDDService::$noCache, 'precondition: the died job left the caches off');
        $this->assertTrue($this->protectedStatic('cachesSnapshotSet'));

        $dddService->resetCachesStateForNewJob();

        $this->assertFalse(DoctrineEntityRegistry::$clearCache);
        $this->assertFalse(VirtualEntityRegistry::$clearCache);
        $this->assertFalse(ClassFinder::$clearCache);
        $this->assertFalse(DDDService::$noCache);
        $this->assertFalse($this->protectedStatic('cachesSnapshotSet'));
    }

    /**
     * The stale frame is the subtler half: a restore that never pushed pops the ENCLOSING scope's frame. After the
     * reset there is nothing to pop, so the restore is the documented no-op instead.
     */
    public function testARestoreAfterTheResetDoesNotPopAStaleFrame(): void
    {
        $dddService = DDDService::instance();
        $dddService->deactivateEntityRightsRestrictions();
        $dddService->resetEntityRightsRestrictionsStateForNewJob();

        $dddService->restoreEntityRightsRestrictionsStateSnapshot();

        $this->assertTrue(DBEntity::getApplyRightsRestrictions());
        $this->assertSame([], $this->protectedStatic('entityRightsRestrictionsStateStack'));
    }
}

<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB;

use DDD\Domain\Base\Repo\DB\DBEntity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class DBEntityDetachManagedOrmInstanceTest extends TestCase
{
    protected function callDetach(EntityManagerInterface $entityManager, string $ormModelClass, int|string $id): void
    {
        $dbEntity = (new \ReflectionClass(DBEntity::class))->newInstanceWithoutConstructor();
        $detachMethod = new ReflectionMethod(DBEntity::class, 'detachManagedOrmInstanceOfRow');
        $detachMethod->setAccessible(true);
        $detachMethod->invoke($dbEntity, $entityManager, $ormModelClass, $id);
    }

    protected function classMetadataWithRoot(string $rootEntityName): ClassMetadata
    {
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->rootEntityName = $rootEntityName;
        return $classMetadata;
    }

    public function testAManagedInstanceOfTheWrittenRowIsDetached(): void
    {
        $managedOrmInstance = new \stdClass();
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('tryGetById')->with(['id' => 42], 'App\Model\DBThing')->willReturn($managedOrmInstance);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($this->classMetadataWithRoot('App\Model\DBThing'));
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->expects($this->once())->method('detach')->with($managedOrmInstance);

        $this->callDetach($entityManager, 'App\Model\DBThing', 42);
    }

    public function testTheIdentityMapIsLookedUpByTheROOTClassOfAnSTIHierarchy(): void
    {
        // #[SubclassIndicator] generates #[ORM\InheritanceType('SINGLE_TABLE')], and Doctrine keys the identity map
        // by the root class — a lookup with the subclass finds nothing and the stale instance would survive.
        $managedOrmInstance = new \stdClass();
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->once())
            ->method('tryGetById')
            ->with(['id' => 7], 'App\Model\DBPostModel')
            ->willReturn($managedOrmInstance);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($this->classMetadataWithRoot('App\Model\DBPostModel'));
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->expects($this->once())->method('detach')->with($managedOrmInstance);

        $this->callDetach($entityManager, 'App\Model\DBEventModel', 7);
    }

    public function testAnUnmanagedRowDetachesNothing(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('tryGetById')->willReturn(false);   // Doctrine returns false, not null

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($this->classMetadataWithRoot('App\Model\DBThing'));
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->expects($this->never())->method('detach');

        $this->callDetach($entityManager, 'App\Model\DBThing', 42);
    }

    public function testAMetadataFailureNeverBreaksTheWriteThatAlreadyHappened(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willThrowException(new RuntimeException('not a mapped class'));
        $entityManager->expects($this->never())->method('detach');

        $this->callDetach($entityManager, 'App\Model\NotMapped', 42);
        $this->assertTrue(true, 'no exception escaped the fail-soft helper');
    }
}

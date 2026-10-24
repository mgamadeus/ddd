<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Counts pings and fails every one, as a lost connection would. */
class FailingPingEntityManager extends DoctrineEntityManager
{
    public int $pings = 0;

    public function isConnectionActive(): bool
    {
        $this->pings++;
        return false;
    }
}

/**
 * Inside a transaction getInstance() never pings: a failed ping replaced the entity manager (and its connection), and
 * the caller's later writes ran in autocommit outside its transaction.
 */
class EntityManagerFactoryTransactionTest extends TestCase
{
    protected array $instancesBefore = [];

    protected function setUp(): void
    {
        $this->instancesBefore = (new ReflectionProperty(EntityManagerFactory::class, 'instances'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(EntityManagerFactory::class, 'instances'))->setValue(null, $this->instancesBefore);
    }

    protected function installFailingPingEntityManager(int $transactionNestingLevel): FailingPingEntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
        // never connects: DBAL connects lazily, and nothing here runs a query
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'never', 'serverVersion' => '10.6.0-MariaDB'],
            $configuration
        );
        (new ReflectionProperty(Connection::class, 'transactionNestingLevel'))->setValue($connection, $transactionNestingLevel);
        $entityManager = new FailingPingEntityManager($connection, $configuration);
        (new ReflectionProperty(EntityManagerFactory::class, 'instances'))->setValue(
            null,
            [EntityManagerFactory::SCOPE_DEFAULT => $entityManager]
        );
        return $entityManager;
    }

    public function testInsideATransactionThePingNeverRunsAndTheEntityManagerStays(): void
    {
        $entityManager = $this->installFailingPingEntityManager(1);

        $this->assertSame($entityManager, EntityManagerFactory::getInstance());
        $this->assertSame(0, $entityManager->pings);
    }

    public function testTheScopeAccessorsNeverCreateAnInstance(): void
    {
        $this->installFailingPingEntityManager(0);

        $this->assertSame([EntityManagerFactory::SCOPE_DEFAULT], EntityManagerFactory::getInstantiatedScopes());
        $this->assertNull(EntityManagerFactory::getExistingInstance(EntityManagerFactory::SCOPE_LEGACY_DB));
        $this->assertSame([EntityManagerFactory::SCOPE_DEFAULT], EntityManagerFactory::getInstantiatedScopes());
    }
}

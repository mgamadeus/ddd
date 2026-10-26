<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Connections;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

class TransactionProbeMessage
{
}

/** The middleware under test without the pre-job reset, so only the post-job transaction handling is observed. */
class TransactionOnlyFreshWorkerStateMiddleware extends FreshWorkerStateMiddleware
{
    protected function resetWorkerState(?string $handlerClass = null): void
    {
    }
}

/**
 * Bug 3: the entity manager is never swapped under a caller's transaction (nested wrapInTransaction failure, a ping
 * after a killed connection), outside a transaction a dead connection is still replaced, a worker job cannot leak its
 * transaction into the next message, and a sync dispatch keeps the request's transaction.
 */
class TransactionSafeConnectionTest extends IntegrationTestCase
{
    protected function businessKeysStoredInCounterRows(): array
    {
        return $this->freshConnection()->fetchFirstColumn('SELECT businessKey FROM CounterRows ORDER BY businessKey');
    }

    /** A second connection of its own — the test's view from outside the connection under test. */
    protected function freshConnection(): \Doctrine\DBAL\Connection
    {
        return DriverManager::getConnection(static::connection()->getParams());
    }

    protected function forceNextPing(DoctrineEntityManager $entityManager): void
    {
        (new ReflectionProperty(DoctrineEntityManager::class, 'lastConnectionCheckTime'))->setValue($entityManager, 0);
    }

    protected function killConnectionOf(DoctrineEntityManager $entityManager): void
    {
        $connectionId = (int)$entityManager->getConnection()->fetchOne('SELECT CONNECTION_ID()');
        $this->freshConnection()->executeStatement("KILL $connectionId");
    }

    public function testANestedWrapInTransactionFailureKeepsTheCallersConnectionAndTransaction(): void
    {
        $entityManager = static::entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->insert('CounterRows', ['businessKey' => 'outer']);
        try {
            $entityManager->wrapInTransaction(static function (): void {
                throw new RuntimeException('inner failure');
            });
        } catch (RuntimeException) {
        }

        $entityManagerAfterFailure = EntityManagerFactory::getInstance();

        $this->assertSame($connection, $entityManagerAfterFailure->getConnection());
        $this->assertTrue($entityManagerAfterFailure->isOpen());
        $this->assertTrue($connection->isTransactionActive(), 'the caller still owns its transaction and decides');
        // without savepoints DBAL marks the outer transaction rollback-only after the inner failure: the caller rolls back
        $connection->rollBack();
        $this->assertSame([], $this->businessKeysStoredInCounterRows());
    }

    public function testAKilledConnectionInsideATransactionMakesTheNextWriteThrowInsteadOfAutocommitting(): void
    {
        $entityManager = static::entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $connection->insert('CounterRows', ['businessKey' => 'before-kill']);
        $this->killConnectionOf($entityManager);
        $this->forceNextPing($entityManager);

        $writeFailed = false;
        try {
            EntityManagerFactory::getInstance()->getConnection()->insert('CounterRows', ['businessKey' => 'after-kill']);
        } catch (Throwable) {
            $writeFailed = true;
        }

        $this->assertTrue($writeFailed, 'the write after the kill must fail, not run in autocommit on a new connection');
        $this->assertSame([], $this->businessKeysStoredInCounterRows());
    }

    public function testOutsideATransactionAFailedPingStillReplacesTheEntityManager(): void
    {
        $entityManager = static::entityManager();
        $this->killConnectionOf($entityManager);
        $this->forceNextPing($entityManager);

        $entityManagerAfterPing = EntityManagerFactory::getInstance();

        $this->assertNotSame($entityManager, $entityManagerAfterPing);
        $this->assertSame(1, (int)$entityManagerAfterPing->getConnection()->fetchOne('SELECT 1'));
    }

    protected function handleThroughMiddleware(Envelope $envelope, callable $handler): void
    {
        $handlerMiddleware = new class ($handler) implements MiddlewareInterface {
            public function __construct(protected $handler)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->handler)();
                return $envelope;
            }
        };
        $middleware = new TransactionOnlyFreshWorkerStateMiddleware();
        $middleware->handle($envelope, new StackMiddleware([$middleware, $handlerMiddleware]));
    }

    public function testAWorkerJobThatThrowsInsideATransactionLeavesNoTransactionForTheNextMessage(): void
    {
        $workerEnvelope = new Envelope(new TransactionProbeMessage(), [new ReceivedStamp('async'), new ConsumedByWorkerStamp()]);
        try {
            $this->handleThroughMiddleware($workerEnvelope, static function (): void {
                $connection = EntityManagerFactory::getInstance()->getConnection();
                $connection->beginTransaction();
                $connection->insert('CounterRows', ['businessKey' => 'leaked']);
                throw new RuntimeException('handler failure');
            });
            $this->fail('the handler exception must surface');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('handler failure', $runtimeException->getMessage());
        }

        $connection = EntityManagerFactory::getInstance()->getConnection();
        $this->assertSame(0, $connection->getTransactionNestingLevel());
        $connection->insert('CounterRows', ['businessKey' => 'next-message']);
        $this->assertSame(['next-message'], $this->businessKeysStoredInCounterRows());
    }

    public function testASyncDispatchInsideAnOpenTransactionKeepsItAndItsWritesCommit(): void
    {
        $connection = static::connection();
        $connection->beginTransaction();
        $connection->insert('CounterRows', ['businessKey' => 'request-write']);

        $this->handleThroughMiddleware(
            new Envelope(new TransactionProbeMessage(), [new ReceivedStamp('sync')]),
            static function (): void {
                EntityManagerFactory::getInstance()->getConnection()->insert('CounterRows', ['businessKey' => 'handler-write']);
            }
        );

        $this->assertTrue($connection->isTransactionActive());
        $connection->commit();
        $this->assertSame(['handler-write', 'request-write'], $this->businessKeysStoredInCounterRows());
    }
}

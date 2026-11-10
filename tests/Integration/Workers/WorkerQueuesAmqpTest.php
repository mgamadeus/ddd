<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Tests\Integration\Workers\Fixtures\FixtureDsnWorkerQueuesService;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The queue count against a real AMQP broker: read by passive declares, so a queue that does not exist is reported and
 * never created, and the transports' own channels keep working. Runs only with DDD_TEST_AMQP_DSN set to a broker's
 * vhost DSN ending in "/", e.g. `amqp://guest:guest@127.0.0.1:5672/%2f/` (a throwaway `rabbitmq` container).
 */
class WorkerQueuesAmqpTest extends TestCase
{
    public function testQueuesAreCountedByPassiveDeclaresAndAMissingQueueIsNotCreated(): void
    {
        $brokerDsn = getenv('DDD_TEST_AMQP_DSN') ?: null;
        if ($brokerDsn === null || !extension_loaded('amqp')) {
            self::markTestSkipped('Set DDD_TEST_AMQP_DSN to the vhost DSN of a throwaway AMQP broker (needs ext-amqp)');
        }
        $suffix = bin2hex(random_bytes(4));
        $firstQueue = 'ddd_test_first_' . $suffix;
        $secondQueue = 'ddd_test_second_' . $suffix;
        $missingQueue = 'ddd_test_missing_' . $suffix;
        $transportDsnsByName = [
            $firstQueue => $brokerDsn . $firstQueue,
            $secondQueue => $brokerDsn . $secondQueue,
            $missingQueue => $brokerDsn . $missingQueue . '?auto_setup=false',
        ];
        $amqpTransportFactory = new AmqpTransportFactory();
        $transportFactoriesByName = [];
        $transportsByName = [];
        foreach ($transportDsnsByName as $transportName => $transportDsn) {
            $transport = $amqpTransportFactory->createTransport($transportDsn, ['transport_name' => $transportName], new PhpSerializer());
            $transportsByName[$transportName] = $transport;
            $transportFactoriesByName[$transportName] = static fn() => $transport;
        }
        for ($messageNumber = 0; $messageNumber < 3; $messageNumber++) {
            $transportsByName[$firstQueue]->send(new Envelope(new stdClass()));
        }
        $transportsByName[$secondQueue]->send(new Envelope(new stdClass()));
        usleep(300000);
        $workerQueuesService = new FixtureDsnWorkerQueuesService(new ServiceLocator($transportFactoriesByName), $transportDsnsByName);

        // twice: the failed declare of the missing queue must not break the next count
        foreach ([1, 2] as $round) {
            $workerQueueCounts = $workerQueuesService->countWaitingMessages([$firstQueue, $missingQueue, $secondQueue, 'not_a_transport']);

            self::assertSame(3, $workerQueueCounts->getByTransport($firstQueue)->waiting, "round $round");
            self::assertSame(WorkerQueueCount::STATE_COUNTED, $workerQueueCounts->getByTransport($firstQueue)->state);
            self::assertSame(1, $workerQueueCounts->getByTransport($secondQueue)->waiting, "round $round");
            self::assertSame(WorkerQueueCount::STATE_QUEUE_NOT_SET_UP, $workerQueueCounts->getByTransport($missingQueue)->state, (string)$workerQueueCounts->getByTransport($missingQueue)->error);
            self::assertNull($workerQueueCounts->getByTransport($missingQueue)->waiting);
            self::assertSame(WorkerQueueCount::STATE_UNKNOWN, $workerQueueCounts->getByTransport('not_a_transport')->state);
        }

        // the transports' own channels still work after the counts
        $transportsByName[$firstQueue]->send(new Envelope(new stdClass()));
        usleep(300000);
        self::assertSame(4, $workerQueuesService->countWaitingMessages([$firstQueue])->getByTransport($firstQueue)->waiting);
        self::assertCount(3, [...$transportsByName[$firstQueue]->get(), ...$transportsByName[$firstQueue]->get(), ...$transportsByName[$firstQueue]->get()]);
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use AMQPChannel;
use AMQPQueue;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Entities\Workers\WorkerQueueCounts;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection as AmqpTransportConnection;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

/**
 * How many messages wait in the transports' queues — asked through the transports' own connections, with exactly the
 * rights the workers have. No broker management API, no extra credentials.
 *
 * - AMQP: a passive queue declare (reads the ready count, creates and changes nothing, needs no configure right) on
 *   one broker connection per host and vhost for the whole pass. A queue that does not exist yet is its own state
 *   ({@see WorkerQueueCount::STATE_QUEUE_NOT_SET_UP}); the declare that found it missing closes its channel, so the
 *   next queue gets a fresh one. Symfony's own count is not used here: it declares the queue for real.
 * - Every other transport: Symfony's {@see MessageCountAwareInterface}; a transport without it is UNKNOWN, never 0.
 *
 * The count is the READY messages. Messages in work are not in it.
 */
class WorkerQueuesService extends Service
{
    public const int AMQP_NOT_FOUND = 404;
    public const string AMQP_SCHEME_PREFIX = 'amqp';

    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        protected ?ContainerInterface $receiverLocator = null,
    ) {
    }

    /**
     * @param string[] $transports Transport names
     * @return WorkerQueueCounts One count per transport, in the given order
     */
    public function countWaitingMessages(array $transports): WorkerQueueCounts
    {
        $workerQueueCounts = new WorkerQueueCounts();
        $amqpTransportsByConnectionKey = [];
        $receiversByTransport = [];
        foreach ($transports as $transport) {
            $receiver = $this->getReceiver($transport);
            $receiversByTransport[$transport] = $receiver;
            if ($receiver instanceof AmqpTransport) {
                $amqpTransportsByConnectionKey[$this->getConnectionKey($transport)][] = $transport;
            }
        }
        $amqpCountsByTransport = [];
        foreach ($amqpTransportsByConnectionKey as $amqpTransports) {
            foreach ($this->countAmqpTransports($amqpTransports, $receiversByTransport) as $workerQueueCount) {
                $amqpCountsByTransport[$workerQueueCount->transport] = $workerQueueCount;
            }
        }
        foreach ($transports as $transport) {
            if (isset($amqpCountsByTransport[$transport])) {
                $workerQueueCounts->add($amqpCountsByTransport[$transport]);
                continue;
            }
            $receiver = $receiversByTransport[$transport];
            if ($receiver === null) {
                $workerQueueCounts->addCount(
                    $transport,
                    WorkerQueueCount::STATE_UNKNOWN,
                    null,
                    $this->getWorkerTransportsService()->hasReceiver($transport) ? 'the transport could not be created (see its DSN)' : 'no such transport'
                );
                continue;
            }
            if (!$receiver instanceof MessageCountAwareInterface) {
                $workerQueueCounts->addCount($transport, WorkerQueueCount::STATE_UNKNOWN, null, 'the transport cannot count its messages');
                continue;
            }
            try {
                $workerQueueCounts->addCount($transport, WorkerQueueCount::STATE_COUNTED, $receiver->getMessageCount());
            } catch (Throwable $throwable) {
                $workerQueueCounts->addCount($transport, WorkerQueueCount::STATE_UNKNOWN, null, $throwable->getMessage());
            }
        }
        return $workerQueueCounts;
    }

    /**
     * The AMQP transports of ONE broker connection (same host, port, user and vhost), counted by passive declares on
     * one connection: the first transport's. Never throws.
     *
     * @param string[] $transports
     * @param array<string, object|null> $receiversByTransport
     * @return WorkerQueueCount[]
     */
    protected function countAmqpTransports(array $transports, array $receiversByTransport): array
    {
        $workerQueueCounts = [];
        $amqpChannel = null;
        $amqpConnection = null;
        foreach ($transports as $transport) {
            try {
                $amqpTransportConnection = $this->getAmqpTransportConnection($receiversByTransport[$transport]);
                $amqpConnection ??= $amqpTransportConnection->channel()->getConnection();
                $waiting = 0;
                foreach ($amqpTransportConnection->getQueueNames() as $queueName) {
                    $amqpChannel ??= new AMQPChannel($amqpConnection);
                    $waiting += $this->countAmqpQueue($amqpChannel, $queueName);
                }
                $workerQueueCounts[] = new WorkerQueueCount($transport, WorkerQueueCount::STATE_COUNTED, $waiting);
            } catch (Throwable $throwable) {
                // a failed declare closes its channel: the next queue gets a fresh one
                $amqpChannel = null;
                $workerQueueCounts[] = (int)$throwable->getCode() === self::AMQP_NOT_FOUND
                    ? new WorkerQueueCount($transport, WorkerQueueCount::STATE_QUEUE_NOT_SET_UP)
                    : new WorkerQueueCount($transport, WorkerQueueCount::STATE_UNKNOWN, null, $throwable->getMessage());
            }
        }
        return $workerQueueCounts;
    }

    /** @return int The queue's ready messages, read by a passive declare (throws with code 404 when it does not exist) */
    protected function countAmqpQueue(AMQPChannel $amqpChannel, string $queueName): int
    {
        $amqpQueue = new AMQPQueue($amqpChannel);
        $amqpQueue->setName($queueName);
        $amqpQueue->setFlags(AMQP_PASSIVE);
        return (int)$amqpQueue->declareQueue();
    }

    /** The transport's own connection object (Symfony keeps it private) */
    protected function getAmqpTransportConnection(object $amqpTransport): AmqpTransportConnection
    {
        return (new ReflectionProperty(AmqpTransport::class, 'connection'))->getValue($amqpTransport);
    }

    /** @return string What identifies the broker connection of the transport: its DSN without the path behind the vhost */
    protected function getConnectionKey(string $transport): string
    {
        $dsn = $this->getWorkerTransportsService()->getTransportDsn($transport) ?? $transport;
        $dsnParts = parse_url($dsn);
        if (!is_array($dsnParts)) {
            return $dsn;
        }
        $vhost = explode('/', ltrim($dsnParts['path'] ?? '', '/'))[0] ?? '';
        return ($dsnParts['scheme'] ?? '') . '://' . ($dsnParts['user'] ?? '') . '@' . ($dsnParts['host'] ?? '')
            . ':' . ($dsnParts['port'] ?? '') . '/' . $vhost;
    }

    protected function getReceiver(string $transport): ?object
    {
        try {
            return $this->receiverLocator !== null && $this->receiverLocator->has($transport)
                ? $this->receiverLocator->get($transport)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return DDDService::instance()->getService(WorkerTransportsService::class);
    }
}

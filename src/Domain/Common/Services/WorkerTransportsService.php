<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Infrastructure\Services\Service;

/**
 * What the compiled container knows about the application's Messenger transports: the receivers, which of them are
 * failure transports, and where each message class is routed. Filled by
 * {@see \DDD\Symfony\CompilerPasses\WorkerTransportsPass} from the transport definitions and the routing — the real
 * wiring, including handlers and transports bound without an attribute.
 */
class WorkerTransportsService extends Service
{
    public const string SYNC_DSN_PREFIX = 'sync://';
    public const string IN_MEMORY_DSN_PREFIX = 'in-memory://';
    public const string TRANSPORT_NAME_SEPARATOR = ',';

    /**
     * @param string[] $receiverNames Names of all transports that can be consumed
     * @param string[] $failureTransportNames Names of the failure transports among them
     * @param string[] $transportDsnsByName Transport name => DSN
     * @param string[] $transportNamesByMessageClass Message class (or `*`) => the transports it is routed to, joined by ","
     */
    public function __construct(
        protected array $receiverNames = [],
        protected array $failureTransportNames = [],
        protected array $transportDsnsByName = [],
        protected array $transportNamesByMessageClass = [],
    ) {
    }

    /** @return string[] Every transport of the container that can be consumed */
    public function getReceiverNames(): array
    {
        return $this->receiverNames;
    }

    /** @return string[] */
    public function getFailureTransportNames(): array
    {
        return $this->failureTransportNames;
    }

    public function hasReceiver(string $transport): bool
    {
        return in_array($transport, $this->receiverNames, true);
    }

    /** @return bool The transport handles its messages inside the dispatching process: no worker consumes it */
    public function isSynchronousTransport(string $transport): bool
    {
        $dsn = $this->getTransportDsn($transport) ?? '';
        return str_starts_with($dsn, self::SYNC_DSN_PREFIX) || str_starts_with($dsn, self::IN_MEMORY_DSN_PREFIX);
    }

    /** @return string[] The transports a worker has to consume: every receiver but the failure and synchronous ones */
    public function getConsumedTransportNames(): array
    {
        return array_values(array_filter(
            $this->receiverNames,
            fn(string $transport): bool => !in_array($transport, $this->failureTransportNames, true)
                && !$this->isSynchronousTransport($transport)
        ));
    }

    /** @return string|null The DSN the transport was configured with; it can carry the broker's credentials */
    public function getTransportDsn(string $transport): ?string
    {
        return $this->transportDsnsByName[$transport] ?? null;
    }

    /** @return string[] Message classes (and `*`) that have a routing entry */
    public function getRoutedMessageClasses(): array
    {
        return array_keys($this->transportNamesByMessageClass);
    }

    /** @return string[] The transports the message class is routed to by its own routing entry */
    public function getTransportNamesForMessageClass(string $messageClass): array
    {
        return array_values(array_filter(
            explode(self::TRANSPORT_NAME_SEPARATOR, $this->transportNamesByMessageClass[$messageClass] ?? '')
        ));
    }
}

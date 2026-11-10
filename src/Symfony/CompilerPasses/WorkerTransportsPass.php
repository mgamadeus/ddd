<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Domain\Common\Services\WorkerTransportsService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hands the application's Messenger wiring to the {@see WorkerTransportsService}: the receivers (transport
 * definitions tagged `messenger.receiver`), the failure transports among them, each transport's DSN and the routing
 * of the message classes. Read from the container, so it is what the workers really run with.
 *
 * Runs before Symfony's MessengerPass, like {@see FreshWorkerStateMiddlewarePass}. An application without Messenger
 * transports gets empty lists.
 */
class WorkerTransportsPass implements CompilerPassInterface
{
    public const string RECEIVER_TAG = 'messenger.receiver';
    public const string SENDERS_LOCATOR_ID = 'messenger.senders_locator';

    public function process(ContainerBuilder $container): void
    {
        $receiverNames = [];
        $failureTransportNames = [];
        $transportDsnsByName = [];
        foreach ($container->findTaggedServiceIds(self::RECEIVER_TAG) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $transportName = $tag['alias'] ?? null;
                if (!is_string($transportName) || $transportName === '' || in_array($transportName, $receiverNames, true)) {
                    continue;
                }
                $receiverNames[] = $transportName;
                if ($tag['is_failure_transport'] ?? false) {
                    $failureTransportNames[] = $transportName;
                }
                $dsn = $container->getDefinition($serviceId)->getArguments()[0] ?? null;
                if (is_string($dsn)) {
                    $transportDsnsByName[$transportName] = $dsn;
                }
            }
        }
        $transportNamesByMessageClass = [];
        if ($container->hasDefinition(self::SENDERS_LOCATOR_ID)) {
            $sendersByMessageClass = $container->getDefinition(self::SENDERS_LOCATOR_ID)->getArguments()[0] ?? [];
            foreach (is_array($sendersByMessageClass) ? $sendersByMessageClass : [] as $messageClass => $senderNames) {
                $transportNamesByMessageClass[(string)$messageClass] = implode(
                    WorkerTransportsService::TRANSPORT_NAME_SEPARATOR,
                    array_map('strval', (array)$senderNames)
                );
            }
        }
        $definition = $container->hasDefinition(WorkerTransportsService::class)
            ? $container->getDefinition(WorkerTransportsService::class)
            : $container->register(WorkerTransportsService::class, WorkerTransportsService::class);
        $definition
            ->setPublic(true)
            ->setArgument('$receiverNames', $receiverNames)
            ->setArgument('$failureTransportNames', $failureTransportNames)
            ->setArgument('$transportDsnsByName', $transportDsnsByName)
            ->setArgument('$transportNamesByMessageClass', $transportNamesByMessageClass);
    }
}

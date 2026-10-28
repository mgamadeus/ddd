<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A framework command yields to an application (or module) command of the same name: when a command name is
 * registered by a class under {@see self::FRAMEWORK_COMMANDS_NAMESPACE} and by another class, the framework's service
 * is removed. Without it the winner would depend on the service registration order. Example: an application with its
 * own `app:runtime-errors:extract` keeps it; one without gets the framework's.
 *
 * Runs after the autoconfiguration has tagged the commands and before the console's command loader is built.
 */
class FrameworkCommandsYieldPass implements CompilerPassInterface
{
    public const string FRAMEWORK_COMMANDS_NAMESPACE = 'DDD\\Symfony\\Commands\\';

    public const string CONSOLE_COMMAND_TAG = 'console.command';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, string[]> $serviceIdsByCommandName method-local: command name → service ids */
        $serviceIdsByCommandName = [];
        foreach ($container->findTaggedServiceIds(self::CONSOLE_COMMAND_TAG) as $serviceId => $tags) {
            foreach ($this->getCommandNames($container, $serviceId, $tags) as $commandName) {
                $serviceIdsByCommandName[$commandName][] = $serviceId;
            }
        }
        foreach ($serviceIdsByCommandName as $serviceIds) {
            $serviceIds = array_values(array_unique($serviceIds));
            if (count($serviceIds) < 2) {
                continue;
            }
            $frameworkServiceIds = array_filter(
                $serviceIds,
                fn(string $serviceId): bool => str_starts_with($this->getServiceClass($container, $serviceId) ?? '', self::FRAMEWORK_COMMANDS_NAMESPACE)
            );
            if ($frameworkServiceIds === [] || count($frameworkServiceIds) === count($serviceIds)) {
                continue;
            }
            foreach ($frameworkServiceIds as $frameworkServiceId) {
                $container->removeDefinition($frameworkServiceId);
            }
        }
    }

    /**
     * The names a command service registers: the tags' `command` attributes, else its #[AsCommand] name (with aliases).
     *
     * @param array<int, array<string, mixed>> $tags
     * @return string[]
     */
    protected function getCommandNames(ContainerBuilder $container, string $serviceId, array $tags): array
    {
        $commandNames = [];
        foreach ($tags as $tag) {
            if (isset($tag['command']) && is_string($tag['command'])) {
                $commandNames = [...$commandNames, ...explode('|', $tag['command'])];
            }
        }
        if ($commandNames === []) {
            $serviceClass = $this->getServiceClass($container, $serviceId);
            if ($serviceClass !== null && class_exists($serviceClass)) {
                foreach ((new ReflectionClass($serviceClass))->getAttributes(AsCommand::class) as $asCommandAttribute) {
                    $commandNames = [...$commandNames, ...explode('|', $asCommandAttribute->newInstance()->name)];
                }
            }
        }
        return array_values(array_filter($commandNames, static fn(string $commandName): bool => $commandName !== ''));
    }

    protected function getServiceClass(ContainerBuilder $container, string $serviceId): ?string
    {
        $serviceClass = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
        $resolvedClass = $container->getParameterBag()->resolveValue($serviceClass);
        return is_string($resolvedClass) ? ltrim($resolvedClass, '\\') : null;
    }
}

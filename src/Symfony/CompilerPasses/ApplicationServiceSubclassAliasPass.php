<?php

declare(strict_types=1);

namespace DDD\Symfony\CompilerPasses;

use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

/**
 * One instance per service the framework injects by type: when the application subclasses one of
 * {@see self::FRAMEWORK_SERVICES_ALIASED_TO_APPLICATION_SUBCLASS} under the mirrored `App\` name (the class
 * {@see DDDService::getService()} already returns), the framework's service id becomes an alias of the
 * application's. Without it a typed injection (`IssuesLogService $issuesLogService` in the capture subscriber) got a
 * second, framework-only instance that bypasses the application's overrides.
 *
 * The tags of the framework definition (IssuesLogService: `monolog.logger` channel `issues`) are carried over to the
 * application definition when it lacks them. Runs before the services are tagged and resolved (BEFORE_OPTIMIZATION,
 * high priority), after the application's and modules' definitions are loaded.
 */
class ApplicationServiceSubclassAliasPass implements CompilerPassInterface
{
    /** @var string[] Framework services aliased to their application subclass */
    public const array FRAMEWORK_SERVICES_ALIASED_TO_APPLICATION_SUBCLASS = [
        IssuesLogService::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (static::FRAMEWORK_SERVICES_ALIASED_TO_APPLICATION_SUBCLASS as $frameworkServiceClass) {
            $applicationServiceClass = $this->getApplicationServiceClass($frameworkServiceClass);
            if (!$container->hasDefinition($frameworkServiceClass) || !$container->hasDefinition($applicationServiceClass)) {
                continue;
            }
            try {
                if (!is_a($applicationServiceClass, $frameworkServiceClass, true)) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            $frameworkDefinition = $container->getDefinition($frameworkServiceClass);
            $applicationDefinition = $container->getDefinition($applicationServiceClass);
            foreach ($frameworkDefinition->getTags() as $tagName => $tagAttributesList) {
                if ($applicationDefinition->hasTag($tagName)) {
                    continue;
                }
                foreach ($tagAttributesList as $tagAttributes) {
                    $applicationDefinition->addTag($tagName, $tagAttributes);
                }
            }
            $container->removeDefinition($frameworkServiceClass);
            $container->setAlias($frameworkServiceClass, $applicationServiceClass)->setPublic(true);
        }
    }

    /** The application's class of a framework service: the same name under `App\` (as {@see DDDService::getService()} maps it) */
    protected function getApplicationServiceClass(string $frameworkServiceClass): string
    {
        return DDDService::APP_ROOT_NAMESPACE . substr($frameworkServiceClass, strlen(DDDService::FRAMEWORK_ROOT_NAMESPACE));
    }
}

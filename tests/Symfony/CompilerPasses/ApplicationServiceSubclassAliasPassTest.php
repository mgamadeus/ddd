<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Symfony\CompilerPasses\ApplicationServiceSubclassAliasPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** Stands in for `App\Infrastructure\Services\IssuesLogService` (a real App class would remap getService() for every later test) */
class ApplicationIssuesLogServiceProbe extends IssuesLogService
{
}

class ProbeApplicationServiceSubclassAliasPass extends ApplicationServiceSubclassAliasPass
{
    protected function getApplicationServiceClass(string $frameworkServiceClass): string
    {
        return ApplicationIssuesLogServiceProbe::class;
    }
}

/**
 * A framework service the application subclasses is one instance: the framework id becomes an alias of the
 * application's definition, which inherits the framework definition's logger channel tag.
 */
class ApplicationServiceSubclassAliasPassTest extends TestCase
{
    public function testTheFrameworkIdBecomesAnAliasOfTheApplicationSubclass(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition(
            IssuesLogService::class,
            (new Definition(IssuesLogService::class))->addTag('monolog.logger', ['channel' => 'issues'])
        );
        $containerBuilder->setDefinition(ApplicationIssuesLogServiceProbe::class, new Definition(ApplicationIssuesLogServiceProbe::class));

        (new ProbeApplicationServiceSubclassAliasPass())->process($containerBuilder);

        self::assertTrue($containerBuilder->hasAlias(IssuesLogService::class));
        self::assertSame(ApplicationIssuesLogServiceProbe::class, (string)$containerBuilder->getAlias(IssuesLogService::class));
        self::assertSame(
            [['channel' => 'issues']],
            $containerBuilder->getDefinition(ApplicationIssuesLogServiceProbe::class)->getTag('monolog.logger')
        );
    }

    public function testWithoutAnApplicationSubclassNothingChanges(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition(IssuesLogService::class, new Definition(IssuesLogService::class));

        (new ApplicationServiceSubclassAliasPass())->process($containerBuilder);

        self::assertTrue($containerBuilder->hasDefinition(IssuesLogService::class));
        self::assertFalse($containerBuilder->hasAlias(IssuesLogService::class));
    }
}

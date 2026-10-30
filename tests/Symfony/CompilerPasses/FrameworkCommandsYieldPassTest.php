<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Symfony\Commands\Common\RuntimeErrors\RuntimeErrorsExtract;
use DDD\Symfony\Commands\Common\RuntimeErrors\RuntimeErrorsProbe;
use DDD\Symfony\CompilerPasses\FrameworkCommandsYieldPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * A framework command yields to an application command of the same name, whichever registered first.
 */
class FrameworkCommandsYieldPassTest extends TestCase
{
    public function testFrameworkCommandYieldsToTheApplicationCommand(): void
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition(RuntimeErrorsExtract::class, (new Definition(RuntimeErrorsExtract::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => RuntimeErrorsExtract::COMMAND_NAME]));
        // tagged without a name: the #[AsCommand] name is read
        $containerBuilder->setDefinition(RuntimeErrorsProbe::class, (new Definition(RuntimeErrorsProbe::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG));
        $containerBuilder->setDefinition('App\\Symfony\\Commands\\RuntimeErrorsExtractCommand', (new Definition(Command::class))
            ->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => RuntimeErrorsExtract::COMMAND_NAME]));

        (new FrameworkCommandsYieldPass())->process($containerBuilder);

        self::assertFalse($containerBuilder->hasDefinition(RuntimeErrorsExtract::class));
        self::assertTrue($containerBuilder->hasDefinition('App\\Symfony\\Commands\\RuntimeErrorsExtractCommand'));
        self::assertTrue($containerBuilder->hasDefinition(RuntimeErrorsProbe::class));
    }
}

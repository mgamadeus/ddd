<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses;

use DDD\Infrastructure\Modules\DDDModules;
use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use DDD\Symfony\Kernels\DDDKernel;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\AbstractFixtureModule;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\AdditionalFixtureModule;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\AttributeTaggedModuleService;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ApplicationModuleListener;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\AliasedModulePrinter;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\ModulePrinterInterface;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\ExcludedModulePlumbing;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\ModuleListenerInterface;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\Lazy\AbstractLazyModuleService;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\Lazy\LazyModuleService;
use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\ModuleWorkerStateResetter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Exposes the protected build() of the kernel and adds one module Composer does not list. */
class AdditionalModuleKernel extends DDDKernel
{
    public function getAdditionalModuleClasses(): array
    {
        return [AdditionalFixtureModule::class];
    }

    public function buildForTest(ContainerBuilder $containerBuilder): void
    {
        $this->build($containerBuilder);
    }
}

/**
 * The module pass runs before Symfony's autoconfiguration, registers the kernel's additional modules and keeps the
 * module list in `ddd.modules`.
 */
class ModuleCompilerPassAutoconfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        $this->forgetDiscoveredModules();
    }

    protected function tearDown(): void
    {
        $this->forgetDiscoveredModules();
    }

    /** discoverModules() caches Composer's list per process; the fixture project has no installed.json */
    protected function forgetDiscoveredModules(): void
    {
        (new ReflectionProperty(ModuleCompilerPass::class, 'discoveredModules'))->setValue(null, null);
    }

    /** A container as the kernel builds it: the fixture project, the kernel's parameter and pass, autoconfiguration */
    protected function buildContainerLikeTheKernel(array $additionalModuleClasses): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', __DIR__ . '/Fixtures');
        $containerBuilder->setParameter(ModuleCompilerPass::ADDITIONAL_MODULES_PARAMETER, $additionalModuleClasses);
        $containerBuilder->registerForAutoconfiguration(WorkerStateResetterInterface::class)
            ->addTag(WorkerStateResetterInterface::TAG);
        $containerBuilder->addCompilerPass(
            new ModuleCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            ModuleCompilerPass::PASS_PRIORITY
        );
        return $containerBuilder;
    }

    public function testAnAdditionalModuleIsRegisteredAndListedInTheModulesParameter(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel(
            [AdditionalFixtureModule::class, AbstractFixtureModule::class, 'Not\\A\\ModuleClass', AdditionalFixtureModule::class]
        );

        $containerBuilder->compile();

        $this->assertSame([AdditionalFixtureModule::class], $containerBuilder->getParameter(ModuleCompilerPass::MODULES_PARAMETER));
        $this->assertTrue($containerBuilder->hasDefinition(AttributeTaggedModuleService::class));
    }

    /** The reason for the priority: attributes on module classes (#[AsMessageHandler], #[AsCommand], …) take effect. */
    public function testAModuleServiceGetsTheTagOfItsAttribute(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel([AdditionalFixtureModule::class]);

        $containerBuilder->compile();

        $this->assertArrayHasKey(AttributeTaggedModuleService::class, $containerBuilder->findTaggedServiceIds('fixture.module_tag'));
    }

    public function testAModuleServiceGetsTheTagOfItsInterfaceExactlyOnce(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel([AdditionalFixtureModule::class]);

        $containerBuilder->compile();

        $taggedServiceIds = $containerBuilder->findTaggedServiceIds(WorkerStateResetterInterface::TAG);
        $this->assertArrayHasKey(ModuleWorkerStateResetter::class, $taggedServiceIds);
        $this->assertCount(1, $taggedServiceIds[ModuleWorkerStateResetter::class]);
    }

    public function testAServiceBelowALazyNamespaceIsLazyAndOnePublicNamespaceMakesItPublic(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel([AdditionalFixtureModule::class]);

        $containerBuilder->compile();

        $this->assertTrue($containerBuilder->getDefinition(LazyModuleService::class)->isLazy());
        $this->assertTrue($containerBuilder->getDefinition(LazyModuleService::class)->isPublic());
        $this->assertFalse($containerBuilder->getDefinition(AttributeTaggedModuleService::class)->isLazy());
    }

    /**
     * Like an application resource block: an #[Exclude] class and an abstract class of a public module namespace are
     * no services. Without that rule the excluded class's unautowirable constructor fails the compilation.
     */
    public function testAnExcludedClassAndAnAbstractClassAreNoServices(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel([AdditionalFixtureModule::class]);
        $containerBuilder->getCompilerPassConfig()->setRemovingPasses([]);

        $containerBuilder->compile();

        $this->assertTrue($containerBuilder->getDefinition(ExcludedModulePlumbing::class)->isAbstract());
        $this->assertTrue($containerBuilder->getDefinition(ExcludedModulePlumbing::class)->hasTag('container.excluded'));
        $this->assertFalse($containerBuilder->hasDefinition(AbstractLazyModuleService::class));
        $this->assertTrue($containerBuilder->getDefinition('.abstract.' . AbstractLazyModuleService::class)->isAbstract());
        $this->assertTrue($containerBuilder->getDefinition(LazyModuleService::class)->isPublic());
    }

    /**
     * Like an interface of an application resource block: a module interface's #[AutoconfigureTag] reaches an
     * application service implementing it, and the one implementing service gets the interface's alias.
     */
    public function testAModuleInterfaceTagsItsApplicationImplementationAndAliasesItsOnlyImplementation(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel([AdditionalFixtureModule::class]);
        $containerBuilder->register(ApplicationModuleListener::class, ApplicationModuleListener::class)
            ->setAutoconfigured(true)
            ->setPublic(true);
        $containerBuilder->getCompilerPassConfig()->setRemovingPasses([]);

        $containerBuilder->compile();

        $this->assertArrayHasKey(ApplicationModuleListener::class, $containerBuilder->findTaggedServiceIds('fixture.module_listener'));
        $this->assertSame(ApplicationModuleListener::class, (string)$containerBuilder->getAlias(ModuleListenerInterface::class));
    }

    /** #[AsAlias] on a module service publishes the alias, as on an application class of a resource block */
    public function testAModuleServiceWithAsAliasGetsItsPublicAlias(): void
    {
        $containerBuilder = $this->buildContainerLikeTheKernel([AdditionalFixtureModule::class]);

        $containerBuilder->compile();

        $this->assertTrue($containerBuilder->hasAlias(ModulePrinterInterface::class));
        $this->assertSame(AliasedModulePrinter::class, (string)$containerBuilder->getAlias(ModulePrinterInterface::class));
        $this->assertTrue($containerBuilder->getAlias(ModulePrinterInterface::class)->isPublic());
    }

    public function testTheKernelHandsOverItsAdditionalModulesAndRunsThePassBeforeTheAutoconfiguration(): void
    {
        $containerBuilder = new ContainerBuilder();

        (new AdditionalModuleKernel('test', false))->buildForTest($containerBuilder);

        $this->assertSame(
            [AdditionalFixtureModule::class],
            $containerBuilder->getParameter(ModuleCompilerPass::ADDITIONAL_MODULES_PARAMETER)
        );
        $passClasses = array_map('get_class', $containerBuilder->getCompilerPassConfig()->getBeforeOptimizationPasses());
        $modulePassPosition = array_search(ModuleCompilerPass::class, $passClasses, true);
        $instanceofPassPosition = array_search(ResolveInstanceofConditionalsPass::class, $passClasses, true);
        $this->assertNotFalse($modulePassPosition);
        $this->assertLessThan($instanceofPassPosition, $modulePassPosition);
    }

    public function testTheRuntimeModuleListComesFromTheParameterAndFallsBackToComposersList(): void
    {
        $containerBuilder = new ContainerBuilder();
        $this->assertSame([], DDDModules::getModuleClasses($containerBuilder, __DIR__ . '/Fixtures'));

        $containerBuilder->setParameter(ModuleCompilerPass::MODULES_PARAMETER, [AdditionalFixtureModule::class]);
        $this->assertSame([AdditionalFixtureModule::class], DDDModules::getModuleClasses($containerBuilder, __DIR__ . '/Fixtures'));
    }
}

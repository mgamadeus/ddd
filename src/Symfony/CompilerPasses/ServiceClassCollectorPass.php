<?php
namespace DDD\Symfony\CompilerPasses;

use DDD\Domain\Base\Entities\StaticRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ServiceClassCollectorPass implements CompilerPassInterface
{
    /**
     * Stores all Service allocations into service_class_map for later access
     * @param ContainerBuilder $container
     * @return void
     */
    public function process(ContainerBuilder $container)
    {
        $serviceClasses = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition->isAbstract() && $definition->getClass()) {
                $serviceClasses[$id] = $container->getParameterBag()->resolveValue($definition->getClass());
            }
        }

        // Define the file path
        $cacheDir = $container->getParameter('kernel.cache_dir');
        $filePath = $cacheDir . '/service_class_map.php';

        // Write the array to the file
        file_put_contents($filePath, '<?php return ' . var_export($serviceClasses, true) . ';');
    }
}
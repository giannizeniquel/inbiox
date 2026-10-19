<?php

namespace App\Automatizacion\DependencyInjection;

use App\Automatizacion\ReglaInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass to register all rules with RuleExecutorService.
 * Automatically discovers all services implementing ReglaInterface.
 */
class ReglaPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Get the RuleExecutorService definition
        if (!$container->has('App\Automatizacion\EjecutorDeReglas')) {
            return;
        }

        $ejecutorDeReglasDefinition = $container->getDefinition('App\Automatizacion\EjecutorDeReglas');

        // Get all service definitions
        $definitions = $container->getDefinitions();

        // Rule classes we know implement RuleInterface
        $clasesDeReglasConocidas = [
            'App\Automatizacion\ReglaDeReaperturaAutomatica',
            'App\Automatizacion\ReglaDeEtiquetadoPorPalabraClave',
        ];

        foreach ($definitions as $id => $definition) {
            if (!$definition instanceof \Symfony\Component\DependencyInjection\Definition) {
                continue;
            }

            $class = $definition->getClass();
            if (!$class) {
                continue;
            }

            // Check if it's one of our known rule classes
            if (in_array($class, $clasesDeReglasConocidas, true)) {
                $ejecutorDeReglasDefinition->addMethodCall('agregarRegla', [new Reference($id)]);
            }
        }
    }
}
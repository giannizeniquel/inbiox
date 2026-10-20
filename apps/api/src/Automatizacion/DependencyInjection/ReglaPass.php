<?php

namespace App\Automatizacion\DependencyInjection;

use App\Automatizacion\ReglaInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass que registra todas las reglas en EjecutorDeReglas.
 * Descubre automáticamente todos los servicios que implementan ReglaInterface.
 */
class ReglaPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Obtiene la definición de EjecutorDeReglas
        if (!$container->has('App\Automatizacion\EjecutorDeReglas')) {
            return;
        }

        $ejecutorDeReglasDefinition = $container->getDefinition('App\Automatizacion\EjecutorDeReglas');

        // Obtiene todas las definiciones de servicios
        $definitions = $container->getDefinitions();

        // Clases de regla que sabemos que implementan ReglaInterface
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

            // Verifica si es una de nuestras clases de regla conocidas
            if (in_array($class, $clasesDeReglasConocidas, true)) {
                $ejecutorDeReglasDefinition->addMethodCall('agregarRegla', [new Reference($id)]);
            }
        }
    }
}
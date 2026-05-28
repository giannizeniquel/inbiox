<?php

namespace App;

use App\DependencyInjection\RulePass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function configure(ContainerConfigurator $container): void
    {
        // Load main services config
        $container->import('config/services.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RulePass());
    }
}

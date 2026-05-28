<?php

namespace App\DependencyInjection;

use App\Domain\Rule\RuleInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass to register all rules with RuleExecutorService.
 * Automatically discovers all services implementing RuleInterface.
 */
class RulePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Get the RuleExecutorService definition
        if (!$container->has('App\Application\Rule\RuleExecutorService')) {
            return;
        }

        $ruleExecutorDefinition = $container->getDefinition('App\Application\Rule\RuleExecutorService');

        // Get all service definitions
        $definitions = $container->getDefinitions();

        // Rule classes we know implement RuleInterface
        $knownRuleClasses = [
            'App\Domain\Rule\AutoCloseRule',
            'App\Domain\Rule\AutoReopenRule',
            'App\Domain\Rule\KeywordTaggingRule',
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
            if (in_array($class, $knownRuleClasses, true)) {
                $ruleExecutorDefinition->addMethodCall('addRule', [new Reference($id)]);
            }
        }
    }
}
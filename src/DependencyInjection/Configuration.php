<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ibexa_form_builder');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('from_email')
            ->defaultValue('')
            ->end()
            ->end();

        return $treeBuilder;
    }
}

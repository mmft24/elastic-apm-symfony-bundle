<?php

declare(strict_types=1);

/*
 * This file is part of the Elastic APM Symfony Bundle.
 *
 * (c) mmft24
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpFoundation\Response;

final class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('elastic_apm');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('interactor')
                    ->info('Service id of the interactor to use, or "auto" to detect the elastic_apm extension.')
                    ->validate()
                        ->ifTrue(static fn($v): bool => !\is_string($v) || '' === $v)
                        ->thenInvalid(
                            'The "interactor" option must be a non-empty string (a service id or "auto"), got %s.',
                        )
                    ->end()
                ->end()
                ->booleanNode('logging')
                    ->info('Write logs to a PSR3 logger whenever we send data to Elastic APM.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('track_memory_usage')
                    ->info('Should memory usage be tracked?')
                    ->defaultFalse()
                ->end()
                ->scalarNode('memory_usage_label')
                    ->info('The name of the label to write memory usage to.')
                    ->defaultValue('memory_usage')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('exceptions')
                    ->canBeDisabled()
                    ->children()
                        ->arrayNode('ignored_exceptions')
                            ->scalarPrototype()->end()
                        ->end()
                        ->booleanNode('unwrap_exceptions')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('capture_min_status_code')
                            ->info(
                                'Minimum HTTP status code an HttpException must carry to be reported to APM. '
                                .'Lower it (e.g. 400) to capture 4xx signal such as auth brute-force or rate-limit storms.',
                            )
                            ->defaultValue(Response::HTTP_INTERNAL_SERVER_ERROR)
                            ->min(100)->max(599)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('deprecations')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('warnings')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('custom_labels')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('custom_context')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('http')
                    ->canBeDisabled()
                    ->children()
                        ->scalarNode('transaction_naming')
                            ->defaultValue('route')
                            ->validate()
                                ->ifNotInArray(['uri', 'route', 'controller', 'service'])
                                ->thenInvalid(
                                    'Invalid transaction naming scheme "%s", must be "uri", "route", "controller" or "service".',
                                )
                            ->end()
                        ->end()
                        ->scalarNode('transaction_naming_service')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('commands')
                    ->canBeDisabled()
                    ->children()
                        ->booleanNode('explicitly_collect_exceptions')
                            ->info(
                                'Should exceptions be explicitly collected? This can conflict with the built-in collection in PHP APM',
                            )
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('sensitive_parameter_names')
                            ->info(
                                'Additional command option/argument names (matched as whole words, case-insensitive) whose values must be redacted before being sent to APM.',
                            )
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

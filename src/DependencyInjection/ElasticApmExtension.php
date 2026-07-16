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

use ElasticApmBundle\Interactor\AdaptiveInteractor;
use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractor;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Interactor\LoggingInteractorDecorator;
use ElasticApmBundle\Listener\CommandListener;
use ElasticApmBundle\Listener\ExceptionListener;
use ElasticApmBundle\TransactionNamingStrategy\ControllerNamingStrategy;
use ElasticApmBundle\TransactionNamingStrategy\RouteNamingStrategy;
use ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface;
use ElasticApmBundle\TransactionNamingStrategy\UriNamingStrategy;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
final class ElasticApmExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $interactorId = $this->getInteractorServiceId($config);

        if ($config['logging']) {
            // Wrap the chosen interactor in the logging decorator and expose the decorator as the interactor.
            // Referencing the concrete service (not the interface alias) keeps this free of a circular reference.
            $container->getDefinition(LoggingInteractorDecorator::class)
                ->setArgument('$interactor', new Reference($interactorId));

            $interactorId = LoggingInteractorDecorator::class;
        }

        $container->setAlias(ElasticApmInteractorInterface::class, $interactorId)->setPublic(
            false,
        );
        $container->setAlias(
            TransactionNamingStrategyInterface::class,
            $this->getTransactionNamingServiceId($config),
        )->setPublic(
            false,
        );

        $container->getDefinition(Config::class)
            ->setArguments(
                [
                    '$customLabels' => $config['custom_labels'],
                    '$customContext' => $config['custom_context'],
                    '$shouldCollectMemoryUsage' => $config['track_memory_usage'],
                    '$memoryUsageLabelName' => $config['memory_usage_label'],
                    '$shouldExplicitlyCollectCommandExceptions' => $config['commands']['explicitly_collect_exceptions'],
                    '$shouldUnwrapExceptions' => $config['exceptions']['unwrap_exceptions'],
                ],
            );

        if ($config['http']['enabled']) {
            $loader->load('http_listener.php');
        }

        if ($config['commands']['enabled']) {
            $loader->load('command_listener.php');

            $container->getDefinition(CommandListener::class)
                ->setArgument(
                    '$additionalSensitiveParameterNames',
                    $config['commands']['sensitive_parameter_names'],
                );
        }

        if ($config['exceptions']['enabled']) {
            $loader->load('exception_listener.php');

            $container->getDefinition(ExceptionListener::class)
                ->setArguments(
                    [
                        '$ignoredExceptions' => $config['exceptions']['ignored_exceptions'],
                        '$captureMinStatusCode' => $config['exceptions']['capture_min_status_code'],
                    ],
                );
        }

        if ($config['deprecations']['enabled']) {
            $loader->load('deprecation_listener.php');
        }

        if ($config['warnings']['enabled']) {
            $loader->load('warning_listener.php');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getInteractorServiceId(array $config): string
    {
        if (!$config['enabled']) {
            return BlackholeInteractor::class;
        }

        if (!isset($config['interactor'])) {
            // Fallback on AdaptiveInteractor.
            return AdaptiveInteractor::class;
        }

        $interactor = (string) $config['interactor'];

        if ('auto' === $interactor) {
            // Check if the extension is loaded or not
            return \extension_loaded('elastic_apm') ? ElasticApmInteractor::class : BlackholeInteractor::class;
        }

        return $interactor;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getTransactionNamingServiceId(array $config): string
    {
        $http = (array) $config['http'];
        $naming = (string) $http['transaction_naming'];

        // Configuration validates $naming against this exact set; the default arm is a
        // defensive guard that should only be reachable if that validation is bypassed.
        return match ($naming) {
            'controller' => ControllerNamingStrategy::class,
            'route' => RouteNamingStrategy::class,
            'uri' => UriNamingStrategy::class,
            'service' => $this->getTransactionNamingServiceServiceId($http),
            default => throw new \InvalidArgumentException(\sprintf(
                'Invalid transaction naming scheme "%s", must be "uri", "route", "controller" or "service".',
                $naming,
            )),
        };
    }

    /**
     * @param array<string, mixed> $http
     */
    private function getTransactionNamingServiceServiceId(array $http): string
    {
        if (!isset($http['transaction_naming_service'])) {
            throw new \LogicException(
                'When using the "service" transaction naming scheme, the "transaction_naming_service" config parameter must be set.',
            );
        }

        return (string) $http['transaction_naming_service'];
    }
}

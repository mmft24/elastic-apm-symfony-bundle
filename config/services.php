<?php

declare(strict_types=1);

use ElasticApmBundle\Interactor\AdaptiveInteractor;
use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractor;
use ElasticApmBundle\Interactor\LoggingInteractorDecorator;
use ElasticApmBundle\TransactionNamingStrategy\ControllerNamingStrategy;
use ElasticApmBundle\TransactionNamingStrategy\RouteNamingStrategy;
use ElasticApmBundle\TransactionNamingStrategy\UriNamingStrategy;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    // Interactors
    $services->set(Config::class)
        ->alias('elastic_apm.config', Config::class)
    ;

    $services->set(ElasticApmInteractor::class)
        ->alias('elastic_apm.interactor.elastic_apm', ElasticApmInteractor::class)
    ;

    $services->set(BlackholeInteractor::class)
        ->alias('elastic_apm.interactor.blackhole', BlackholeInteractor::class)
    ;

    $services->set(AdaptiveInteractor::class)
        ->args(
            [
                service(ElasticApmInteractor::class),
                service(BlackholeInteractor::class),
            ],
        )
        ->alias('elastic_apm.interactor.adaptive', AdaptiveInteractor::class)
    ;

    $services->set(LoggingInteractorDecorator::class)
        ->alias('elastic_apm.interactor.logging_decorator', LoggingInteractorDecorator::class)
    ;

    // Transaction Naming Strategies
    $services->set(ControllerNamingStrategy::class)
        ->alias('elastic_apm.transaction_naming.controller', ControllerNamingStrategy::class)
    ;

    $services->set(RouteNamingStrategy::class)
        ->alias('elastic_apm.transaction_naming.route', RouteNamingStrategy::class)
    ;

    $services->set(UriNamingStrategy::class)
        ->alias('elastic_apm.transaction_naming.uri', UriNamingStrategy::class)
    ;
};

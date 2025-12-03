<?php

declare(strict_types=1);

use ElasticApmBundle\Listener\ExceptionListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(ExceptionListener::class)
        ->alias('elastic_apm.listener.exception', ExceptionListener::class)
    ;
};

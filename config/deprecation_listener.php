<?php

declare(strict_types=1);

use ElasticApmBundle\Listener\DeprecationListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(DeprecationListener::class)
        ->alias('elastic_apm.listener.deprecation', DeprecationListener::class)
        ->public()
    ; // Needs to be public as it's accessed from bundle boot
};

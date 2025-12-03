<?php

declare(strict_types=1);

use ElasticApmBundle\Listener\CommandListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(CommandListener::class)
        ->alias('elastic_apm.listener.command', CommandListener::class)
    ;
};

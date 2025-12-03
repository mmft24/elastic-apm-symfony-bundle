<?php

declare(strict_types=1);

use ElasticApmBundle\Listener\FinishRequestListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(FinishRequestListener::class)
        ->alias('elastic_apm.listener.finish_request', FinishRequestListener::class)
    ;
};

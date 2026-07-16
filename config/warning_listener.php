<?php

declare(strict_types=1);

use ElasticApmBundle\Listener\WarningListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(WarningListener::class)
        // Reset the dedup cache between requests and Messenger messages so errors are not silently dropped
        // for the whole lifetime of a long-running worker process.
        ->tag('kernel.reset', ['method' => 'reset'])
        ->alias('elastic_apm.listener.warning', WarningListener::class)
        ->public()
    ; // Needs to be public as it's accessed from bundle boot
};

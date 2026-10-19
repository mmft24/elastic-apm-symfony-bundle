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

    // Both the class id and the alias must be public: the bundle looks the listener up by class id at boot, and
    // a private service is removed from the compiled container, which silently disabled error reporting.
    $services->set(DeprecationListener::class)
        ->public()
        // Reset the dedup cache between requests and Messenger messages so errors are not silently dropped
        // for the whole lifetime of a long-running worker process.
        ->tag('kernel.reset', ['method' => 'reset'])
        ->alias('elastic_apm.listener.deprecation', DeprecationListener::class)
        ->public()
    ;
};

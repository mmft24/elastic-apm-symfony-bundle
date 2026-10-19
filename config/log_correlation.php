<?php

declare(strict_types=1);

use ElasticApmBundle\Monolog\TraceContextProcessor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    // Not autoconfigured: MonologBundle would otherwise add an unrestricted monolog.processor tag on top of the
    // channel/handler-restricted ones the extension adds.
    $services->set(TraceContextProcessor::class)
        ->autoconfigure(false)
        ->alias('elastic_apm.monolog.trace_context_processor', TraceContextProcessor::class)
    ;
};

<?php

declare(strict_types=1);

use ElasticApmBundle\Messenger\PropagateTracingListener;
use ElasticApmBundle\Messenger\TracingMiddleware;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Messenger\WorkerTransactionListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(TracingStamper::class)
        ->alias('elastic_apm.messenger.stamper', TracingStamper::class)
    ;

    $services->set(PropagateTracingListener::class)
        ->alias('elastic_apm.messenger.listener.propagate_tracing', PropagateTracingListener::class)
    ;

    $services->set(WorkerTransactionListener::class)
        ->alias('elastic_apm.messenger.listener.worker_transaction', WorkerTransactionListener::class)
    ;

    // Referenced by id from framework.messenger.buses.<bus>.middleware, so this id is part of the public contract.
    $services->set(TracingMiddleware::class)
        ->alias('elastic_apm.messenger.middleware', TracingMiddleware::class)
    ;
};

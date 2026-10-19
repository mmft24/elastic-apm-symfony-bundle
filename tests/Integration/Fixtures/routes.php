<?php

declare(strict_types=1);

use ElasticApmBundle\Tests\Integration\Fixtures\TestController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('hello', '/hello/{name}')->controller(TestController::class.'::hello');
    $routes->add('hello_array', '/hello-array/{name}')->controller([TestController::class, 'hello']);
    $routes->add('fail', '/fail')->controller(TestController::class.'::fail');
    $routes->add('not_found', '/not-found')->controller(TestController::class.'::notFound');
    $routes->add('unavailable', '/unavailable')->controller(TestController::class.'::unavailable');
    $routes->add('suspicious', '/suspicious')->controller(TestController::class.'::suspicious');
    $routes->add('ignored', '/ignored')->controller(TestController::class.'::ignored');
    $routes->add('ignored_subclass', '/ignored-subclass')->controller(TestController::class.'::ignoredSubclass');
};

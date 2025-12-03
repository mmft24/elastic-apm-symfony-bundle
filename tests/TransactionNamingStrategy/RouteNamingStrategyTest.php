<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\TransactionNamingStrategy;

use ElasticApmBundle\TransactionNamingStrategy\RouteNamingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(RouteNamingStrategy::class)]
final class RouteNamingStrategyTest extends TestCase
{
    private RouteNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new RouteNamingStrategy();
    }

    public function testGetTransactionNameReturnsUnknownWhenRouteNotSet(): void
    {
        $request = new Request();

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Unknown Symfony route', $result);
    }

    public function testGetTransactionNameReturnsUnknownWhenRouteIsEmpty(): void
    {
        $request = new Request();
        $request->attributes->set('_route', '');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Unknown Symfony route', $result);
    }

    public function testGetTransactionNameReturnsRouteName(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_homepage');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('app_homepage', $result);
    }

    public function testGetTransactionNameWithComplexRouteName(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_user_profile_edit');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('app_user_profile_edit', $result);
    }

    public function testGetTransactionNameWithApiRoute(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'api_v1_users_list');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('api_v1_users_list', $result);
    }

    public function testGetTransactionNameIgnoresOtherAttributes(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'test_route');
        $request->attributes->set('_controller', 'App\\Controller\\TestController::index');
        $request->attributes->set('id', 123);

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('test_route', $result);
    }
}

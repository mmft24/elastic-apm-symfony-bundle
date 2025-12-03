<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\TransactionNamingStrategy;

use ElasticApmBundle\TransactionNamingStrategy\ControllerNamingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ControllerNamingStrategy::class)]
final class ControllerNamingStrategyTest extends TestCase
{
    private ControllerNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new ControllerNamingStrategy();
    }

    public function testGetTransactionNameReturnsUnknownWhenControllerNotSet(): void
    {
        $request = new Request();

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Unknown Symfony controller', $result);
    }

    public function testGetTransactionNameReturnsUnknownWhenControllerIsEmpty(): void
    {
        $request = new Request();
        $request->attributes->set('_controller', '');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Unknown Symfony controller', $result);
    }

    public function testGetTransactionNameWithStringController(): void
    {
        $request = new Request();
        $request->attributes->set('_controller', 'App\\Controller\\HomeController::index');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('App\\Controller\\HomeController::index', $result);
    }

    public function testGetTransactionNameWithClosureController(): void
    {
        $request = new Request();
        $request->attributes->set('_controller', function (): void {});

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Closure controller', $result);
    }

    public function testGetTransactionNameWithInvokableController(): void
    {
        $controller = new class {
            public function __invoke(): void {}
        };

        $request = new Request();
        $request->attributes->set('_controller', $controller);

        $result = $this->strategy->getTransactionName($request);

        $this->assertStringStartsWith('Callback controller: class@anonymous', $result);
        $this->assertStringEndsWith('::__invoke()', $result);
    }

    public function testGetTransactionNameWithCallableArrayWithObjectInstance(): void
    {
        $controller = new class {
            public function action(): void {}
        };

        $request = new Request();
        $request->attributes->set('_controller', [$controller, 'action']);

        $result = $this->strategy->getTransactionName($request);

        $this->assertStringStartsWith('Callback controller: class@anonymous', $result);
        $this->assertStringEndsWith('::action()', $result);
    }

    public function testGetTransactionNameWithCallableArrayWithClassName(): void
    {
        $request = new Request();
        $request->attributes->set('_controller', [\DateTime::class, 'createFromFormat']);

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Callback controller: DateTime::createFromFormat()', $result);
    }

    public function testGetTransactionNameWithCallableString(): void
    {
        $request = new Request();
        $request->attributes->set('_controller', 'strlen');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('Callback controller: strlen()', $result);
    }
}

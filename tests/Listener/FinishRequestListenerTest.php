<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\FinishRequestListener;
use ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(FinishRequestListener::class)]
final class FinishRequestListenerTest extends TestCase
{
    private ElasticApmInteractorInterface&\PHPUnit\Framework\MockObject\MockObject $interactor;
    private TransactionNamingStrategyInterface&\PHPUnit\Framework\MockObject\MockObject $namingStrategy;

    public function testGetSubscribedEvents(): void
    {
        $events = FinishRequestListener::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::FINISH_REQUEST, $events);
        $this->assertSame([['onFinishRequest', 255]], $events[KernelEvents::FINISH_REQUEST]);
    }

    public function testOnFinishRequestSetsTransactionNameForMainRequest(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->namingStrategy->expects($this->once())
            ->method('getTransactionName')
            ->with($request)
            ->willReturn('test-transaction');

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
            ->with('test-transaction');

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $listener->onFinishRequest($event);
    }

    public function testOnFinishRequestIgnoresSubRequest(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->namingStrategy->expects($this->never())
            ->method('getTransactionName');

        $this->interactor->expects($this->never())
            ->method('setTransactionName');

        $this->interactor->expects($this->never())
            ->method('addContextFromConfig');

        $listener->onFinishRequest($event);
    }

    public function testOnFinishRequestAddsContextFromConfig(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->namingStrategy->expects($this->once())
            ->method('getTransactionName')
            ->willReturn('transaction-name');

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $listener->onFinishRequest($event);
    }

    public function testOnFinishRequestWithDifferentTransactionNames(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $transactionNames = ['homepage', 'api_users_list', 'admin_dashboard'];

        foreach ($transactionNames as $transactionName) {
            $namingStrategy = $this->createMock(TransactionNamingStrategyInterface::class);
            $interactor = $this->createMock(ElasticApmInteractorInterface::class);
            $listener = new FinishRequestListener($interactor, $namingStrategy);

            $namingStrategy->expects($this->once())
                ->method('getTransactionName')
                ->willReturn($transactionName);

            $interactor->expects($this->once())
                ->method('setTransactionName')
                ->with($transactionName);

            $listener->onFinishRequest($event);
        }
    }

    private function createListener(): FinishRequestListener
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->namingStrategy = $this->createMock(TransactionNamingStrategyInterface::class);

        return new FinishRequestListener($this->interactor, $this->namingStrategy);
    }
}

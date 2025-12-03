<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\ExceptionListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(ExceptionListener::class)]
final class ExceptionListenerTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject|ElasticApmInteractorInterface $interactor;
    private ExceptionListener $listener;

    protected function setUp(): void
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->listener = new ExceptionListener($this->interactor, []);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = ExceptionListener::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        $this->assertSame(['onKernelException', 0], $events[KernelEvents::EXCEPTION]);
    }

    public function testOnKernelExceptionNoticesNonHttpException(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $this->listener->onKernelException($event);
    }

    public function testOnKernelExceptionIgnoresHttpException(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new NotFoundHttpException('Not found');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $this->listener->onKernelException($event);
    }

    public function testOnKernelExceptionIgnoresConfiguredExceptions(): void
    {
        $listener = new ExceptionListener($this->interactor, [\RuntimeException::class]);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionNoticesNonIgnoredException(): void
    {
        $listener = new ExceptionListener($this->interactor, [\InvalidArgumentException::class]);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionWithMultipleIgnoredException(): void
    {
        $listener = new ExceptionListener(
            $this->interactor,
            [\RuntimeException::class, \InvalidArgumentException::class],
        );

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        // Test first ignored exception
        $exception1 = new \RuntimeException('Test');
        $event1 = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception1);

        // Test second ignored exception
        $exception2 = new \InvalidArgumentException('Test');
        $event2 = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception2);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $listener->onKernelException($event1);
        $listener->onKernelException($event2);
    }
}

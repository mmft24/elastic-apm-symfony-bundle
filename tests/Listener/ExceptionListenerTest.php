<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\ExceptionListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(ExceptionListener::class)]
final class ExceptionListenerTest extends TestCase
{
    private ElasticApmInteractorInterface&\PHPUnit\Framework\MockObject\MockObject $interactor;

    public function testGetSubscribedEvents(): void
    {
        $events = ExceptionListener::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        $this->assertSame(['onKernelException', 0], $events[KernelEvents::EXCEPTION]);
    }

    public function testOnKernelExceptionNoticesNonHttpException(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
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

    public function testOnKernelExceptionIgnoresHttpException(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $exception = new NotFoundHttpException('Not found');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionNoticesServerErrorHttpException(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        // A 5xx HTTP error is a genuine server failure and must reach APM.
        $exception = new ServiceUnavailableHttpException(null, 'Service down');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionNoticesGeneric500HttpException(): void
    {
        $listener = $this->createListener();
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $exception = new HttpException(500, 'Internal error');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionCapturesClientErrorWhenThresholdLowered(): void
    {
        // Lowering the threshold to 400 means 4xx errors (e.g. auth brute-force) reach APM.
        $listener = $this->createListener([], 400);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $exception = new HttpException(401, 'Unauthorized');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionStillIgnoresErrorsBelowLoweredThreshold(): void
    {
        $listener = $this->createListener([], 400);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        // 3xx redirect is below the 400 threshold and must still be skipped.
        $exception = new HttpException(301, 'Moved');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionIgnoresSubclassOfIgnoredException(): void
    {
        // \OutOfBoundsException extends \RuntimeException, so ignoring the parent must ignore the child too.
        $listener = $this->createListener([\RuntimeException::class]);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \OutOfBoundsException('Out of bounds');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionIgnoresConfiguredExceptions(): void
    {
        $listener = $this->createListener([\RuntimeException::class]);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $listener->onKernelException($event);
    }

    public function testOnKernelExceptionNoticesNonIgnoredException(): void
    {
        $listener = $this->createListener([\InvalidArgumentException::class]);

        $kernel = $this->createStub(HttpKernelInterface::class);
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
        $listener = $this->createListener([\RuntimeException::class, \InvalidArgumentException::class]);

        $kernel = $this->createStub(HttpKernelInterface::class);
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

    /**
     * @param list<class-string<\Throwable>> $ignoredExceptions
     */
    private function createListener(array $ignoredExceptions = [], int $captureMinStatusCode = 500): ExceptionListener
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);

        return new ExceptionListener($this->interactor, $ignoredExceptions, $captureMinStatusCode);
    }
}

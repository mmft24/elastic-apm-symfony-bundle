<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Exception\DeprecationException;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\DeprecationListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeprecationListener::class)]
final class DeprecationListenerTest extends TestCase
{
    private ElasticApmInteractorInterface&\PHPUnit\Framework\MockObject\MockObject $interactor;
    private DeprecationListener $listener;

    protected function setUp(): void
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->listener = new DeprecationListener($this->interactor);
    }

    public function testRegisterSetsErrorHandler(): void
    {
        $this->listener->register();

        // Trigger a deprecation to test the handler is set
        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->isInstanceOf(DeprecationException::class));

        @\trigger_error('Deprecated', \E_USER_DEPRECATED);
        \restore_error_handler();
    }

    public function testRegisterOnlyRegistersOnce(): void
    {
        $this->listener->register();
        $this->listener->register(); // Second call should not register again

        // Should only be called once for the single deprecation
        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable');

        @\trigger_error('Deprecated', \E_USER_DEPRECATED);
        \restore_error_handler();
    }

    public function testUnregisterRestoresErrorHandler(): void
    {
        $this->listener->register();
        $this->listener->unregister();

        // After unregistering, the interactor should not be called
        $this->interactor->expects($this->never())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        @\trigger_error('Deprecated', \E_USER_DEPRECATED);
    }

    public function testUnregisterWithoutRegisterDoesNothing(): void
    {
        // Calling unregister() before register() must be a safe no-op that never
        // touches the interactor (no handler was installed to forward to it).
        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $this->listener->unregister();
    }

    public function testErrorHandlerOnlyHandlesDeprecations(): void
    {
        $this->listener->register();

        // Non-deprecation errors should not trigger the interactor
        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        @\trigger_error('Warning', \E_USER_WARNING);
        \restore_error_handler();
    }

    public function testErrorHandlerCreatesDeprecationExceptionWithCorrectData(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(fn($exception): bool => $exception instanceof DeprecationException
                && 'Test deprecation' === $exception->getMessage()
                && \E_USER_DEPRECATED === $exception->getSeverity()));

        @\trigger_error('Test deprecation', \E_USER_DEPRECATED);
        \restore_error_handler();
    }
}

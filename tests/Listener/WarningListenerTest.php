<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Exception\WarningException;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\WarningListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WarningListener::class)]
final class WarningListenerTest extends TestCase
{
    private ElasticApmInteractorInterface&\PHPUnit\Framework\MockObject\MockObject $interactor;
    private WarningListener $listener;

    protected function setUp(): void
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->listener = new WarningListener($this->interactor);
    }

    protected function tearDown(): void
    {
        // Make sure we unregister listener first, then restore any remaining handlers
        $this->listener->unregister();
    }

    public function testRegisterSetsErrorHandler(): void
    {
        $this->listener->register();

        // Trigger a warning and verify it's handled
        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(fn($throwable): bool => $throwable instanceof WarningException
                && \str_contains($throwable->getMessage(), 'Test warning')));

        @\trigger_error('Test warning', \E_USER_WARNING);
    }

    public function testRegisterOnlyRegistersOnce(): void
    {
        $this->listener->register();
        $this->listener->register();

        // Should only handle warning once even though register was called twice
        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable');

        @\trigger_error('Test warning', \E_USER_WARNING);
    }

    public function testUnregisterRestoresErrorHandler(): void
    {
        $this->listener->register();
        $this->listener->unregister();

        // After unregister, warnings should not be captured
        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        // Set a temporary handler to verify our handler is gone
        \set_error_handler(fn(): true => true);
        @\trigger_error('Test warning', \E_USER_WARNING);
        \restore_error_handler();

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }

    public function testUnregisterWhenNotRegisteredDoesNothing(): void
    {
        // Calling unregister() before register() must be a safe no-op and not restore an unrelated handler.
        // No handler is installed, so the interactor must never be touched.
        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        $this->listener->unregister();
    }

    public function testHandlesEWarning(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(fn($throwable): bool => $throwable instanceof WarningException
                && \E_USER_WARNING === $throwable->getSeverity()));

        @\trigger_error('Test E_USER_WARNING', \E_USER_WARNING);
    }

    public function testHandlesEUserWarning(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(fn($throwable): bool => $throwable instanceof WarningException));

        @\trigger_error('Test user warning', \E_USER_WARNING);
    }

    public function testDoesNotHandleOtherErrorTypes(): void
    {
        $this->listener->register();

        // Should not capture notices or other error types
        $this->interactor->expects($this->never())
            ->method('noticeThrowable');

        @\trigger_error('Test notice', \E_USER_NOTICE);

        $this->addToAssertionCount(1);
    }

    public function testCallsPreviousErrorHandler(): void
    {
        $called = false;
        $previousHandler = function ($type, $msg, $file, $line) use (&$called) {
            $called = true;

            return false;
        };

        \set_error_handler($previousHandler);

        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable');

        @\trigger_error('Test warning', \E_USER_WARNING);

        $this->assertTrue($called, 'Previous error handler should be called');

        \restore_error_handler();
    }

    public function testMultipleWarningsAreAllCaptured(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->exactly(2))
            ->method('addContextFromConfig');

        $this->interactor->expects($this->exactly(2))
            ->method('noticeThrowable');

        @\trigger_error('First warning', \E_USER_WARNING);
        @\trigger_error('Second warning', \E_USER_WARNING);
    }

    public function testIdenticalWarningIsReportedOnlyOnce(): void
    {
        $this->listener->register();

        // A hot code path emitting the same warning (same type/file/line/message) repeatedly must not flood APM:
        // only the first is reported. The loop keeps the trigger site on a single line so the dedup key matches.
        $this->interactor->expects($this->once())
            ->method('noticeThrowable');

        for ($i = 0; $i < 3; ++$i) {
            @\trigger_error('Repeated warning', \E_USER_WARNING);
        }
    }

    public function testResetReenablesReportingOfPreviouslySeenWarning(): void
    {
        $this->listener->register();

        // The dedup cache is scoped to a transaction. After the services resetter clears it between requests or
        // Messenger messages, the same warning must be reported again rather than silently dropped.
        $this->interactor->expects($this->exactly(2))
            ->method('noticeThrowable');

        for ($i = 0; $i < 2; ++$i) {
            @\trigger_error('Warning across transactions', \E_USER_WARNING);
            $this->listener->reset();
        }
    }

    public function testRegisterUnregisterCycle(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->interactor->expects($this->once())
            ->method('noticeThrowable');

        @\trigger_error('Captured warning', \E_USER_WARNING);

        $this->listener->unregister();

        // Set a temporary handler to suppress the warning
        \set_error_handler(fn(): true => true);
        @\trigger_error('Not captured warning', \E_USER_WARNING);
        \restore_error_handler();

        $this->addToAssertionCount(1);
    }
}

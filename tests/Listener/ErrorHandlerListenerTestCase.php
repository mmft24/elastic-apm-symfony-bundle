<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\AbstractErrorHandlerListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour shared by every error-handler listener, exercised against a real set_error_handler() chain.
 *
 * Each test runs on top of a "base" handler that stands in for whatever the application registered before the
 * listener (Symfony's ErrorHandler, a legacy framework, ...). It records every call and returns a configurable
 * value, so the tests can assert both that the chain is honoured and what PHP sees as the final return value.
 */
abstract class ErrorHandlerListenerTestCase extends TestCase
{
    protected ElasticApmInteractorInterface&MockObject $interactor;
    protected AbstractErrorHandlerListener $listener;

    /**
     * @var list<array{int, string, string, int}>
     */
    protected array $baseCalls = [];
    protected mixed $baseReturn = true;
    private \Closure $baseHandler;
    private int $errorReporting;
    private string|false $displayErrors;
    private string|false $logErrors;

    #[\Override]
    protected function setUp(): void
    {
        // PHPUnit narrows error_reporting() while running tests; pin it so "loud" means loud.
        $this->errorReporting = \error_reporting(\E_ALL);
        // A previous handler returning false hands the error to PHP's built-in handler; keep it from printing or
        // logging so only error_get_last() reflects it.
        $this->displayErrors = \ini_set('display_errors', '0');
        $this->logErrors = \ini_set('log_errors', '0');
        \error_clear_last();

        $this->baseHandler = function (int $type, string $message, string $file, int $line): mixed {
            $this->baseCalls[] = [$type, $message, $file, $line];

            return $this->baseReturn;
        };
        \set_error_handler($this->baseHandler);

        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->listener = $this->createListener($this->interactor);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->listener->unregister();
        \restore_error_handler();
        \error_reporting($this->errorReporting);
        \ini_set('display_errors', (string) $this->displayErrors);
        \ini_set('log_errors', (string) $this->logErrors);
        \error_clear_last();
    }

    abstract protected function createListener(ElasticApmInteractorInterface $interactor): AbstractErrorHandlerListener;

    /**
     * An error level the listener reports that can be raised with trigger_error().
     */
    abstract protected function reportedLevel(): int;

    /**
     * An error level the listener must ignore.
     */
    abstract protected function ignoredLevel(): int;

    /**
     * @return class-string<\ErrorException>
     */
    abstract protected function throwableClass(): string;

    public function testLoudErrorIsReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())->method('addContextFromConfig');
        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(fn(\Throwable $e): bool => $e instanceof \ErrorException
                && $this->throwableClass() === $e::class
                && 'loud' === $e->getMessage()
                && $this->reportedLevel() === $e->getSeverity()
                && __FILE__ === $e->getFile()));

        \trigger_error('loud', $this->reportedLevel());

        $this->assertCount(1, $this->baseCalls);
    }

    public function testSilencedErrorsAreReportedWhenEnabled(): void
    {
        $this->listener->setReportSilencedErrors(true);
        $this->listener->register();

        $this->interactor->expects($this->exactly(2))->method('noticeThrowable');

        @\trigger_error('silenced', $this->reportedLevel());
        \error_reporting(0);
        \trigger_error('excluded', $this->reportedLevel());
    }

    public function testOtherErrorLevelsAreNotReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->never())->method('noticeThrowable');

        \trigger_error('other', $this->ignoredLevel());

        $this->assertCount(1, $this->baseCalls);
    }

    public function testPreviousHandlerReturningNullIsPassedThrough(): void
    {
        $this->baseReturn = null;
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        $this->assertNull($this->installedHandler()(\E_USER_NOTICE, 'direct', __FILE__, __LINE__));

        // A user handler returning anything but false stops PHP's built-in handler, so error_get_last() stays
        // empty. Casting null to false would make PHP log the error a second time.
        \trigger_error('null chain', $this->reportedLevel());
        $this->assertNull(\error_get_last());
    }

    public function testPreviousHandlerReturningFalseIsPassedThrough(): void
    {
        $this->baseReturn = false;
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        $this->assertFalse($this->installedHandler()(\E_USER_NOTICE, 'direct', __FILE__, __LINE__));

        \trigger_error('false chain', $this->reportedLevel());
        $this->assertSame('false chain', \error_get_last()['message'] ?? null);
    }

    public function testPreviousHandlerReturningTrueIsPassedThrough(): void
    {
        $this->baseReturn = true;
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        $this->assertTrue($this->installedHandler()(\E_USER_NOTICE, 'direct', __FILE__, __LINE__));

        \trigger_error('true chain', $this->reportedLevel());
        $this->assertNull(\error_get_last());
    }

    public function testPreviousHandlerNonBooleanReturnIsPassedThroughUnchanged(): void
    {
        $this->baseReturn = 0;
        $this->listener->register();

        $this->interactor->expects($this->never())->method('noticeThrowable');

        $this->assertSame(0, $this->installedHandler()(\E_USER_NOTICE, 'direct', __FILE__, __LINE__));
    }

    public function testWithoutPreviousHandlerReturnsFalse(): void
    {
        // Reset to PHP's default handling so the listener has no previous handler to delegate to.
        \set_error_handler(null);

        try {
            $this->listener->register();

            $this->interactor->expects($this->once())->method('noticeThrowable');

            $this->assertFalse($this->installedHandler()(\E_USER_NOTICE, 'direct', __FILE__, __LINE__));

            \trigger_error('no previous', $this->reportedLevel());
            $this->assertSame('no previous', \error_get_last()['message'] ?? null);
        } finally {
            $this->listener->unregister();
            \restore_error_handler();
        }

        $this->assertSame($this->baseHandler, $this->installedHandler());
    }

    public function testPreviousHandlerReceivesOriginalArguments(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        $line = __LINE__ + 1;
        \trigger_error('forwarded', $this->reportedLevel());

        $this->assertSame([[$this->reportedLevel(), 'forwarded', __FILE__, $line]], $this->baseCalls);
    }

    public function testHandlerToleratesMissingFileAndLine(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(static fn(\Throwable $e): bool => 'no location' === $e->getMessage()
                && '' === $e->getFile()
                && 0 === $e->getLine()));

        $this->assertTrue($this->installedHandler()($this->reportedLevel(), 'no location'));
        $this->assertSame([[$this->reportedLevel(), 'no location', '', 0]], $this->baseCalls);
    }

    public function testRegisterTwiceIsIdempotent(): void
    {
        $this->listener->register();
        $handler = $this->installedHandler();
        $this->listener->register();

        $this->assertSame($handler, $this->installedHandler());

        $this->interactor->expects($this->once())->method('noticeThrowable');
        \trigger_error('once', $this->reportedLevel());

        // A single unregister() must be enough to fully remove the listener.
        $this->listener->unregister();
        $this->assertSame($this->baseHandler, $this->installedHandler());
    }

    public function testUnregisterRestoresPreviousHandler(): void
    {
        $this->listener->register();
        $this->assertNotSame($this->baseHandler, $this->installedHandler());

        $this->listener->unregister();
        $this->assertSame($this->baseHandler, $this->installedHandler());

        $this->interactor->expects($this->never())->method('noticeThrowable');
        \trigger_error('after unregister', $this->reportedLevel());
        $this->assertCount(1, $this->baseCalls);
    }

    public function testUnregisterBelowALaterHandlerKeepsTheChainIntact(): void
    {
        $this->baseReturn = false;
        $this->listener->register();
        $ours = $this->installedHandler();

        $laterCalls = [];
        /** @var callable|null $laterPrevious assigned once the handler below is installed */
        $laterPrevious = null;
        $laterHandler = static function (int $type, string $message, string $file, int $line) use (
            &$laterCalls,
            &$laterPrevious,
        ): mixed {
            $laterCalls[] = $message;

            return \is_callable($laterPrevious) ? $laterPrevious($type, $message, $file, $line) : false;
        };
        $laterPrevious = \set_error_handler($laterHandler);
        $later = $this->installedHandler();

        try {
            $this->listener->unregister();

            // Popping blindly would have removed the later handler instead of ours.
            $this->assertSame($later, $this->installedHandler());

            $this->interactor->expects($this->never())->method('addContextFromConfig');
            $this->interactor->expects($this->never())->method('noticeThrowable');

            \trigger_error('after unregister', $this->reportedLevel());

            $this->assertSame(['after unregister'], $laterCalls);
            $this->assertSame([[$this->reportedLevel(), 'after unregister']], $this->baseCallsWithoutLocation());
            // The base handler returned false through the chain, so PHP's built-in handler still ran.
            $this->assertSame('after unregister', \error_get_last()['message'] ?? null);
        } finally {
            \restore_error_handler();
        }

        // Ours is on top again and still a pass-through; a second unregister() now removes it.
        $this->assertSame($ours, $this->installedHandler());
        $this->listener->unregister();
        $this->assertSame($this->baseHandler, $this->installedHandler());
    }

    public function testRegisterAfterUnregisterBelowALaterHandlerDoesNotStackASecondCopy(): void
    {
        $this->listener->register();
        \set_error_handler(static fn(): bool => false);

        try {
            $this->listener->unregister();
            $this->listener->register();
        } finally {
            \restore_error_handler();
        }

        $this->interactor->expects($this->once())->method('noticeThrowable');
        \trigger_error('reactivated', $this->reportedLevel());
        $this->assertCount(1, $this->baseCalls);

        $this->listener->unregister();
        $this->assertSame($this->baseHandler, $this->installedHandler());
    }

    public function testUnregisterWithoutRegisterIsNoop(): void
    {
        $this->interactor->expects($this->never())->method('noticeThrowable');

        $this->listener->unregister();

        $this->assertSame($this->baseHandler, $this->installedHandler());
    }

    public function testCanBeRegisteredAgainAfterUnregister(): void
    {
        $this->listener->register();
        $this->listener->unregister();
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');
        \trigger_error('re-registered', $this->reportedLevel());
        $this->assertCount(1, $this->baseCalls);
    }

    public function testIdenticalErrorIsReportedOnlyOnce(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        // Same type/file/line/message: the loop keeps the trigger site on a single line.
        for ($i = 0; $i < 3; ++$i) {
            \trigger_error('repeated', $this->reportedLevel());
        }

        $this->assertCount(3, $this->baseCalls);
    }

    public function testDistinctErrorsAreAllReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->exactly(2))->method('noticeThrowable');

        \trigger_error('first', $this->reportedLevel());
        \trigger_error('second', $this->reportedLevel());
    }

    public function testResetClearsDedupCache(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->exactly(2))->method('noticeThrowable');

        for ($i = 0; $i < 2; ++$i) {
            \trigger_error('across transactions', $this->reportedLevel());
            $this->listener->reset();
        }
    }

    public function testSubclassCanCustomiseFiltering(): void
    {
        $listener = $this->createFilteringListener($this->interactor);
        $listener->register();

        try {
            $this->interactor->expects($this->once())
                ->method('noticeThrowable')
                ->with($this->callback(static fn(\Throwable $e): bool => 'kept' === $e->getMessage()));

            \trigger_error('ignored by subclass', $this->reportedLevel());
            \trigger_error('kept', $this->reportedLevel());
        } finally {
            $listener->unregister();
        }

        $this->assertCount(2, $this->baseCalls);
    }

    /**
     * A subclass that only overrides the filtering seam, as an application would to drop known-noisy messages.
     */
    abstract protected function createFilteringListener(
        ElasticApmInteractorInterface $interactor,
    ): AbstractErrorHandlerListener;

    protected function installedHandler(): callable
    {
        $handler = \set_error_handler(static fn(): bool => false);
        \restore_error_handler();
        $this->assertIsCallable($handler);

        return $handler;
    }

    /**
     * @return list<array{int, string}>
     */
    protected function baseCallsWithoutLocation(): array
    {
        return \array_map(static fn(array $call): array => [$call[0], $call[1]], $this->baseCalls);
    }
}

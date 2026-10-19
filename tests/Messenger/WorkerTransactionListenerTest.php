<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use Elastic\Apm\TransactionInterface;
use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Messenger\WorkerTransactionListener;
use ElasticApmBundle\Tests\Messenger\Fixtures\CreatesTransactionSpies;
use ElasticApmBundle\Tests\Messenger\Fixtures\ExpectedRetryExceptionInterface;
use ElasticApmBundle\Tests\Messenger\Fixtures\RecordingInteractor;
use ElasticApmBundle\Tests\Messenger\Fixtures\RetryableException;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use ElasticApmBundle\Tests\Messenger\Fixtures\TransactionSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

#[CoversClass(WorkerTransactionListener::class)]
final class WorkerTransactionListenerTest extends TestCase
{
    use CreatesTransactionSpies;
    private TransactionSpy $processTransaction;

    /** @var list<TransactionSpy> */
    private array $messageTransactions = [];
    private RecordingInteractor $interactor;

    protected function setUp(): void
    {
        $this->processTransaction = $this->createTransactionSpy();
        $this->interactor = new RecordingInteractor(
            function () {
                $spy = $this->createTransactionSpy();
                $this->messageTransactions[] = $spy;

                return $spy->transaction;
            },
            $this->processTransaction->transaction,
        );
    }

    public function testSubscribesToWorkerEventsAfterOtherListeners(): void
    {
        $events = WorkerTransactionListener::getSubscribedEvents();

        foreach (
            [
                WorkerStartedEvent::class,
                WorkerMessageReceivedEvent::class,
                WorkerMessageHandledEvent::class,
                WorkerMessageFailedEvent::class,
                WorkerMessageSkipEvent::class,
                WorkerRunningEvent::class,
                WorkerStoppedEvent::class,
            ] as $event
        ) {
            $this->assertArrayHasKey($event, $events);
            $this->assertIsArray($events[$event]);
            // Must run after SendFailedMessageForRetryListener (100), which decides willRetry().
            $this->assertLessThan(0, $events[$event][1]);
        }
    }

    public function testReceiveDiscardsProcessTransactionAndBeginsMessageTransaction(): void
    {
        $envelope = $this->envelope(self::PRODUCER_TRACEPARENT);

        $this->createListener()->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertTrue($this->processTransaction->discarded);
        $this->assertCount(1, $this->interactor->begun);
        $this->assertSame('messenger:consume async '.TestMessage::class, $this->interactor->begun[0]['name']);
        $this->assertSame('message', $this->interactor->begun[0]['type']);
        $this->assertSame(['traceparent' => self::PRODUCER_TRACEPARENT], $this->interactor->begun[0]['headers']);
        $this->assertSame($this->messageTransactions[0]->transaction, $this->interactor->current);
    }

    // tracestate carries the sample rate (es=s:<rate>); the agent needs it to continue the trace faithfully.
    public function testReceiveHandsEveryStampedHeaderToTheAgent(): void
    {
        $headers = ['traceparent' => self::PRODUCER_TRACEPARENT, 'tracestate' => 'es=s:0.25,other=vendor'];
        $envelope = new Envelope(new TestMessage(), [new ApmTracingStamp($headers)]);

        $this->createListener()->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertSame($headers, $this->interactor->begun[0]['headers']);
    }

    public function testReceiveUsesConfiguredTransactionType(): void
    {
        $listener = new WorkerTransactionListener($this->interactor, 'queue');

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));

        $this->assertSame('queue', $this->interactor->begun[0]['type']);
    }

    public function testReceiveWithoutStampStartsNewTrace(): void
    {
        $this->createListener()->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(null), 'async'));

        $this->assertCount(1, $this->interactor->begun);
        $this->assertSame([], $this->interactor->begun[0]['headers']);
    }

    public function testReceiveDoesNotBeginWhenMessageWillNotBeHandled(): void
    {
        $event = new WorkerMessageReceivedEvent($this->envelope(), 'async');
        $event->shouldHandle(false);

        $this->createListener()->onMessageReceived($event);

        $this->assertSame([], $this->interactor->begun);
        $this->assertFalse($this->processTransaction->discarded);
    }

    public function testWorkerStartedDiscardsProcessTransactionOnce(): void
    {
        $listener = $this->createListener();

        $listener->onWorkerStarted(new WorkerStartedEvent($this->worker()));
        $listener->onWorkerStarted(new WorkerStartedEvent($this->worker()));
        // Like agent 1.15, the discarded transaction is still reported as current.
        $this->assertSame($this->processTransaction->transaction, $this->interactor->current);
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));

        $this->assertTrue($this->processTransaction->discarded);
        $this->assertSame(1, $this->processTransaction->endCalls);
        $this->assertCount(1, $this->interactor->begun);
    }

    public function testNoopMessageTransactionIsNotTrackedNorEnded(): void
    {
        $noop = $this->createTransactionSpy([], noop: true);
        $interactor = new RecordingInteractor(
            static fn(): TransactionInterface => $noop->transaction,
            $this->processTransaction->transaction,
        );
        $listener = new WorkerTransactionListener($interactor);
        $envelope = $this->envelope();

        $listener->onWorkerStarted(new WorkerStartedEvent($this->worker()));
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $listener->onWorkerStopped(new WorkerStoppedEvent($this->worker()));

        $this->assertSame(0, $noop->endCalls);
        $this->assertNull($noop->result);
        $this->assertSame(['beginCurrentTransactionFromHeaders'], $interactor->callNames());
        // Only the already-ended process transaction remains current, and TracingStamper ignores ended ones.
        $this->assertTrue($interactor->current?->hasEnded());
    }

    public function testReceiveDoesNotDiscardNoopOrEndedTransactions(): void
    {
        $noop = $this->createTransactionSpy([], noop: true);
        $this->interactor->current = $noop->transaction;

        $this->createListener()->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));

        $this->assertFalse($noop->discarded);
        $this->assertCount(1, $this->interactor->begun);
    }

    public function testHandledSetsSuccessAndEndsTransaction(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $transaction = $this->messageTransactions[0];
        $this->assertSame('MSG 2xx', $transaction->result);
        $this->assertSame('success', $transaction->outcome);
        $this->assertSame(1, $transaction->endCalls);
        $this->assertNull($this->interactor->current);
        $this->assertSame(
            ['beginCurrentTransactionFromHeaders', 'addContextFromConfig', 'endCurrentTransaction'],
            $this->interactor->callNames(),
        );
    }

    public function testFailedSetsFailureNoticesThrowableAndEndsTransaction(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();
        $exception = new \RuntimeException('boom');

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', $exception));

        $transaction = $this->messageTransactions[0];
        $this->assertSame('MSG 5xx', $transaction->result);
        $this->assertSame('failure', $transaction->outcome);
        $this->assertSame(1, $transaction->endCalls);
        $this->assertSame([$exception], $this->interactor->noticed);
        $this->assertSame(
            ['beginCurrentTransactionFromHeaders', 'addContextFromConfig', 'noticeThrowable', 'endCurrentTransaction'],
            $this->interactor->callNames(),
        );
    }

    public function testHandlerFailedExceptionIsUnwrappedToTheHandlerExceptions(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();
        $first = new \RuntimeException('first handler');
        $second = new \LogicException('second handler');
        $nested = new \DomainException('nested bus handler');
        $failure = new HandlerFailedException(
            $envelope,
            [
                'first' => $first,
                'second' => $second,
                'nested' => new HandlerFailedException($envelope, ['first' => $nested]),
            ],
        );

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', $failure));

        $this->assertSame([$first, $second, $nested], $this->interactor->noticed);
    }

    public function testDelayedMessageHandlingExceptionIsUnwrapped(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();
        $cause = new \RuntimeException('delayed handler');

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed(
            new WorkerMessageFailedEvent($envelope, 'async', new DelayedMessageHandlingException([$cause], $envelope)),
        );

        $this->assertSame([$cause], $this->interactor->noticed);
    }

    /**
     * @return iterable<string, array{bool, \Throwable, bool}>
     */
    public static function retryMatrix(): iterable
    {
        yield 'retry + ignored interface' => [true, new RetryableException('expected'), false];
        yield 'retry + ignored class' => [true, new \OverflowException('expected'), false];
        yield 'retry + not ignored' => [true, new \RuntimeException('unexpected'), true];
        yield 'no retry + ignored interface' => [false, new RetryableException('final attempt'), true];
        yield 'no retry + not ignored' => [false, new \RuntimeException('unexpected'), true];
    }

    #[DataProvider('retryMatrix')]
    public function testIgnoredRetryableExceptions(bool $willRetry, \Throwable $exception, bool $expectNoticed): void
    {
        $listener = new WorkerTransactionListener(
            $this->interactor,
            'message',
            [ExpectedRetryExceptionInterface::class, \OverflowException::class],
        );
        $envelope = $this->envelope();
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'async',
            new HandlerFailedException($envelope, [$exception]),
        );

        if ($willRetry) {
            $event->setForRetry();
        }

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed($event);

        $this->assertSame($expectNoticed ? [$exception] : [], $this->interactor->noticed);
        // The attempt still failed, whether or not the error is reported.
        $this->assertSame('MSG 5xx', $this->messageTransactions[0]->result);
        $this->assertSame('failure', $this->messageTransactions[0]->outcome);
        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
    }

    public function testOnlyIgnoredHandlerExceptionsAreSkippedOnRetry(): void
    {
        $listener = new WorkerTransactionListener($this->interactor, 'message', [
            ExpectedRetryExceptionInterface::class
        ]);
        $envelope = $this->envelope();
        $unexpected = new \RuntimeException('unexpected');
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'async',
            new HandlerFailedException($envelope, [new RetryableException('expected'), $unexpected]),
        );
        $event->setForRetry();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed($event);

        $this->assertSame([$unexpected], $this->interactor->noticed);
    }

    public function testHandledOrFailedForAnotherMessageDoesNotTouchTheOpenTransaction(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();
        $other = $this->envelope();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($other, 'async'));
        $listener->onMessageFailed(new WorkerMessageFailedEvent($other, 'async', new \RuntimeException()));

        $this->assertSame(0, $this->messageTransactions[0]->endCalls);
        $this->assertNull($this->messageTransactions[0]->result);
        $this->assertSame([], $this->interactor->noticed);
    }

    public function testHandledWithoutReceiveDoesNothing(): void
    {
        $this->createListener()->onMessageHandled(new WorkerMessageHandledEvent($this->envelope(), 'async'));

        $this->assertSame([], $this->interactor->calls);
        $this->assertSame(0, $this->processTransaction->endCalls);
    }

    public function testNextReceiveEndsTransactionLeftOpen(): void
    {
        $listener = $this->createListener();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));

        $this->assertCount(2, $this->messageTransactions);
        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
        $this->assertSame(0, $this->messageTransactions[1]->endCalls);
    }

    public function testSkipEndsTransactionLeftOpen(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();

        // WorkerMessageSkipEvent was added in symfony/messenger 7.2; on older versions exercise the handler with
        // the base event it accepts, so the test still runs (CI fails on skipped tests).
        $skipEvent = \class_exists(WorkerMessageSkipEvent::class)
            ? new WorkerMessageSkipEvent($envelope, 'async')
            : new class($envelope, 'async') extends AbstractWorkerMessageEvent {};

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageSkipped($skipEvent);

        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
        $this->assertNull($this->interactor->current);
    }

    public function testWorkerRunningEndsTransactionLeftOpen(): void
    {
        $listener = $this->createListener();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $listener->onWorkerRunning(new WorkerRunningEvent($this->worker(), false));

        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
    }

    public function testWorkerStoppedEndsTransactionLeftOpen(): void
    {
        $listener = $this->createListener();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($this->envelope(), 'async'));
        $listener->onWorkerStopped(new WorkerStoppedEvent($this->worker()));

        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
    }

    public function testTransactionIsNotEndedTwice(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $listener->onWorkerRunning(new WorkerRunningEvent($this->worker(), false));
        $listener->onWorkerStopped(new WorkerStoppedEvent($this->worker()));

        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
    }

    public function testEndsOwnTransactionEvenWhenItIsNoLongerCurrent(): void
    {
        $listener = $this->createListener();
        $envelope = $this->envelope();

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $foreign = $this->createTransactionSpy();
        $this->interactor->current = $foreign->transaction;
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
        $this->assertSame(0, $foreign->endCalls);
        $this->assertNotContains('endCurrentTransaction', $this->interactor->callNames());
    }

    public function testWorksWithBlackholeInteractor(): void
    {
        $listener = new WorkerTransactionListener(new BlackholeInteractor());
        $envelope = $this->envelope();

        $listener->onWorkerStarted(new WorkerStartedEvent($this->worker()));
        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $listener->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException()));
        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $listener->onWorkerStopped(new WorkerStoppedEvent($this->worker()));

        $this->addToAssertionCount(1);
    }

    private function createListener(): WorkerTransactionListener
    {
        return new WorkerTransactionListener($this->interactor);
    }

    private function envelope(?string $traceParent = self::PRODUCER_TRACEPARENT): Envelope
    {
        return new Envelope(
            new TestMessage(),
            null === $traceParent ? [] : [new ApmTracingStamp(['traceparent' => $traceParent])],
        );
    }

    private function worker(): Worker
    {
        return new Worker([], new MessageBus());
    }
}

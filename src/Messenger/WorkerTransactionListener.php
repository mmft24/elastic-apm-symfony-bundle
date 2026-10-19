<?php

declare(strict_types=1);

/*
 * This file is part of the Elastic APM Symfony Bundle.
 *
 * (c) mmft24
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\Messenger;

use Elastic\Apm\TransactionInterface;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;

/**
 * Turns every message consumed by a Messenger worker into its own APM transaction, continuing the producer's trace.
 */
final class WorkerTransactionListener implements EventSubscriberInterface
{
    public const string RESULT_SUCCESS = 'MSG 2xx';

    public const string RESULT_FAILURE = 'MSG 5xx';

    // Run after every other listener: the received listeners may still veto handling, and the failed listeners
    // decide whether the message will be retried.
    private const int PRIORITY = -1024;
    private ?TransactionInterface $transaction = null;
    private ?object $message = null;

    /**
     * @param list<class-string<\Throwable>> $ignoredRetryableExceptions
     */
    public function __construct(
        private readonly ElasticApmInteractorInterface $interactor,
        private readonly string $transactionType = 'message',
        private readonly array $ignoredRetryableExceptions = [],
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onMessageReceived', self::PRIORITY],
            WorkerMessageHandledEvent::class => ['onMessageHandled', self::PRIORITY],
            WorkerMessageFailedEvent::class => ['onMessageFailed', self::PRIORITY],
            WorkerMessageSkipEvent::class => ['onMessageSkipped', self::PRIORITY],
            WorkerRunningEvent::class => ['onWorkerRunning', self::PRIORITY],
            WorkerStoppedEvent::class => ['onWorkerStopped', self::PRIORITY],
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->endOpenTransaction();

        if (!$event->shouldHandle()) {
            return;
        }

        $this->discardProcessTransaction();

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $stamp = $envelope->last(ApmTracingStamp::class);

        $this->transaction = $this->interactor->beginCurrentTransaction(
            \sprintf('messenger:consume %s %s', $event->getReceiverName(), $message::class),
            $this->transactionType,
            null,
            TraceParentParser::parse($stamp?->getHeader('traceparent')),
        );
        $this->message = null === $this->transaction ? null : $message;
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $transaction = $this->trackedTransaction($event);

        if (null === $transaction) {
            return;
        }

        $transaction->setResult(self::RESULT_SUCCESS);
        $transaction->setOutcome('success');
        $this->interactor->addContextFromConfig();

        $this->endOpenTransaction();
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $transaction = $this->trackedTransaction($event);

        if (null === $transaction) {
            return;
        }

        $transaction->setResult(self::RESULT_FAILURE);
        $transaction->setOutcome('failure');
        $this->interactor->addContextFromConfig();

        foreach ($this->unwrap($event->getThrowable()) as $throwable) {
            if ($event->willRetry() && $this->isIgnoredWhenRetried($throwable)) {
                continue;
            }

            $this->interactor->noticeThrowable($throwable);
        }

        $this->endOpenTransaction();
    }

    // A skipped message is never handled, so there is nothing to report; just make sure nothing stays open.
    public function onMessageSkipped(AbstractWorkerMessageEvent $event): void
    {
        $this->endOpenTransaction();
    }

    // Dispatched after each message: closes the transaction of a message whose handled/failed event is deferred
    // (batch handlers) or never came, so it cannot leak into the next message.
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $this->endOpenTransaction();
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->endOpenTransaction();
    }

    private function trackedTransaction(AbstractWorkerMessageEvent $event): ?TransactionInterface
    {
        return $event->getEnvelope()->getMessage() === $this->message ? $this->transaction : null;
    }

    // The agent starts a transaction for the whole `messenger:consume` process. It would last as long as the worker
    // and swallow every message's spans, so it is dropped in favour of one transaction per message.
    private function discardProcessTransaction(): void
    {
        $transaction = $this->interactor->getCurrentTransaction();

        if (null !== $transaction && !$transaction->isNoop() && !$transaction->hasEnded()) {
            // discard() also ends the transaction, which clears it as the agent's current transaction.
            $transaction->discard();
        }
    }

    private function endOpenTransaction(): void
    {
        $transaction = $this->transaction;
        $this->transaction = null;
        $this->message = null;

        if (null === $transaction || $transaction->hasEnded()) {
            return;
        }

        if ($this->interactor->getCurrentTransaction() === $transaction) {
            $this->interactor->endCurrentTransaction();

            return;
        }

        // Something else became the current transaction while the message was handled; still end ours.
        $transaction->end();
    }

    /**
     * Messenger wraps handler exceptions; the wrapper only says "handling failed", so report what it wraps.
     *
     * @return list<\Throwable>
     */
    private function unwrap(\Throwable $throwable): array
    {
        if (!$throwable instanceof WrappedExceptionsInterface) {
            return [$throwable];
        }

        $unwrapped = [];

        foreach ($throwable->getWrappedExceptions() as $wrapped) {
            \array_push($unwrapped, ...$this->unwrap($wrapped));
        }

        return [] === $unwrapped ? [$throwable] : $unwrapped;
    }

    private function isIgnoredWhenRetried(\Throwable $throwable): bool
    {
        foreach ($this->ignoredRetryableExceptions as $ignored) {
            if ($throwable instanceof $ignored) {
                return true;
            }
        }

        return false;
    }
}

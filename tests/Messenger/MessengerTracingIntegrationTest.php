<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Messenger\PropagateTracingListener;
use ElasticApmBundle\Messenger\TracingMiddleware;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Messenger\WorkerTransactionListener;
use ElasticApmBundle\Tests\Messenger\Fixtures\CreatesTransactionSpies;
use ElasticApmBundle\Tests\Messenger\Fixtures\ExpectedRetryExceptionInterface;
use ElasticApmBundle\Tests\Messenger\Fixtures\RecordingInteractor;
use ElasticApmBundle\Tests\Messenger\Fixtures\RetryableException;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use ElasticApmBundle\Tests\Messenger\Fixtures\TransactionSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Producer bus -> serializing in-memory transport -> Worker, with only the agent replaced by a recording fake.
 */
#[CoversClass(PropagateTracingListener::class)]
#[CoversClass(TracingMiddleware::class)]
#[CoversClass(TracingStamper::class)]
#[CoversClass(WorkerTransactionListener::class)]
final class MessengerTracingIntegrationTest extends TestCase
{
    use CreatesTransactionSpies;

    private const string TRANSPORT = 'memory';
    private TransactionSpy $processTransaction;

    /** @var list<TransactionSpy> */
    private array $messageTransactions = [];
    private RecordingInteractor $interactor;
    private EventDispatcher $dispatcher;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        // Producer side: the HTTP request (or command) that dispatches the messages.
        $this->processTransaction = $this->createTransactionSpy();
        $this->interactor = new RecordingInteractor(
            function () {
                $spy = $this->createTransactionSpy(
                    ['traceparent' => '00-'.self::PRODUCER_TRACE_ID.'-'.\sprintf(
                        '%016x',
                        \count($this->messageTransactions) + 1
                    ).'-01'],
                );
                $this->messageTransactions[] = $spy;

                return $spy->transaction;
            },
            $this->processTransaction->transaction,
        );

        $this->dispatcher = new EventDispatcher();
        $this->transport = new InMemoryTransport(new PhpSerializer());
    }

    public function testEachConsumedMessageGetsItsOwnTransactionInTheProducerTrace(): void
    {
        $this->dispatcher->addSubscriber(new PropagateTracingListener(new TracingStamper($this->interactor)));
        $bus = $this->createBus(new SendMessageMiddleware($this->senders(), $this->dispatcher));

        $bus->dispatch(new TestMessage('ok'));
        $bus->dispatch(new TestMessage('expected-retry'));
        $bus->dispatch(new TestMessage('ok'));

        foreach ($this->transport->getSent() as $sent) {
            $this->assertSame(
                ['traceparent' => self::PRODUCER_TRACEPARENT],
                $sent->last(ApmTracingStamp::class)?->getHeaders(),
            );
        }

        // ok, failing attempt (retried), ok, final failing attempt.
        $this->runWorker($bus, 4);

        $this->assertTrue($this->processTransaction->discarded);
        $this->assertCount(4, $this->interactor->begun);

        foreach ($this->interactor->begun as $begun) {
            $this->assertSame('messenger:consume '.self::TRANSPORT.' '.TestMessage::class, $begun['name']);
            $this->assertSame(self::PRODUCER_TRACE_ID, $begun['tracingData']?->traceId);
            $this->assertSame(self::PRODUCER_PARENT_ID, $begun['tracingData']->parentId);
        }

        $this->assertSame(
            ['MSG 2xx', 'MSG 5xx', 'MSG 2xx', 'MSG 5xx'],
            \array_map(static fn(TransactionSpy $spy): ?string => $spy->result, $this->messageTransactions),
        );

        foreach ($this->messageTransactions as $transaction) {
            $this->assertSame(1, $transaction->endCalls);
        }

        // The retried attempt is expected noise; only the final failure is reported.
        $this->assertCount(1, $this->interactor->noticed);
        $this->assertInstanceOf(RetryableException::class, $this->interactor->noticed[0]);

        $this->assertTransactionsNeverOverlap();
        $this->assertNull($this->interactor->current);
    }

    public function testMiddlewarePropagatesOnBusesWithoutEventDispatcher(): void
    {
        $bus = $this->createBus(
            new TracingMiddleware(new TracingStamper($this->interactor)),
            new SendMessageMiddleware($this->senders()),
        );

        $bus->dispatch(new TestMessage('ok'));

        $this->runWorker($bus, 1);

        $this->assertCount(1, $this->interactor->begun);
        $this->assertSame(self::PRODUCER_TRACE_ID, $this->interactor->begun[0]['tracingData']?->traceId);
        $this->assertSame('MSG 2xx', $this->messageTransactions[0]->result);
        $this->assertSame(1, $this->messageTransactions[0]->endCalls);
        $this->assertNull($this->interactor->current);
    }

    private function createBus(\Symfony\Component\Messenger\Middleware\MiddlewareInterface ...$middleware): MessageBus
    {
        $attempts = 0;
        $handler = static function (TestMessage $message) use (&$attempts): void {
            if ('expected-retry' === $message->payload) {
                throw new RetryableException('attempt '.++$attempts);
            }
        };

        return new MessageBus([
            ...$middleware,
            new HandleMessageMiddleware(new HandlersLocator([TestMessage::class => [$handler]])),
        ]);
    }

    private function senders(): SendersLocator
    {
        return new SendersLocator(
            [TestMessage::class => [self::TRANSPORT]],
            new ServiceLocator([self::TRANSPORT => fn(): InMemoryTransport => $this->transport]),
        );
    }

    private function runWorker(MessageBus $bus, int $messages): void
    {
        $this->dispatcher->addSubscriber(
            new WorkerTransactionListener($this->interactor, 'message', [ExpectedRetryExceptionInterface::class]),
        );
        $this->dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator([self::TRANSPORT => fn(): InMemoryTransport => $this->transport]),
            new ServiceLocator([self::TRANSPORT => static fn(): MultiplierRetryStrategy => new MultiplierRetryStrategy(
                1,
                0
            )]),
        ));
        $this->dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($messages));
        $this->dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(5));

        (new Worker([self::TRANSPORT => $this->transport], $bus, $this->dispatcher))->run(['sleep' => 1000]);
    }

    private function assertTransactionsNeverOverlap(): void
    {
        $lifecycle = \array_values(\array_filter(
            $this->interactor->callNames(),
            static fn(string $call): bool => \in_array(
                $call,
                ['beginCurrentTransaction', 'endCurrentTransaction'],
                true
            ),
        ));

        $this->assertSame(
            \array_merge(
                ...\array_fill(0, \count($this->interactor->begun), [
                    'beginCurrentTransaction',
                    'endCurrentTransaction'
                ])
            ),
            $lifecycle,
        );
    }
}

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use Elastic\Apm\ElasticApm;
use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractor;
use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Messenger\PropagateTracingListener;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Messenger\WorkerTransactionListener;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use ElasticApmBundle\Tests\Support\RealAgent;
use ElasticApmBundle\Tests\Support\RecordingEventSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Produce -> serializing transport -> Worker, driving the real agent and asserting on what it would ship.
 *
 * Requires a usable agent (see {@see RealAgent}).
 */
#[CoversClass(PropagateTracingListener::class)]
#[CoversClass(TracingStamper::class)]
#[CoversClass(WorkerTransactionListener::class)]
#[CoversClass(ElasticApmInteractor::class)]
final class RealAgentMessengerTracingTest extends TestCase
{
    private const string TRANSPORT = 'memory';

    private const string UPSTREAM_TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string UPSTREAM_PARENT_ID = 'b7ad6b7169203331';

    private RecordingEventSink $sink;
    private ElasticApmInteractor $interactor;
    private EventDispatcher $dispatcher;
    private InMemoryTransport $transport;

    /** @var list<string> id of the current transaction while each message was handled */
    private array $handledIn = [];

    protected function setUp(): void
    {
        if (!RealAgent::isUsable()) {
            $this->markTestSkipped(RealAgent::skipReason());
        }

        $this->sink = RealAgent::record();
        $this->interactor = new ElasticApmInteractor(new Config([], [], false, 'memory_usage', false, false));
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new PropagateTracingListener(new TracingStamper($this->interactor)));
        $this->dispatcher->addSubscriber(new WorkerTransactionListener($this->interactor));
        $this->transport = new InMemoryTransport(new PhpSerializer());
    }

    protected function tearDown(): void
    {
        if (RealAgent::isUsable()) {
            RealAgent::restore();
        }
    }

    public function testConsumedMessagesContinueTheProducerTraceUnderTheActiveSpan(): void
    {
        $bus = $this->createBus();

        // The producer continues an upstream trace that was sampled at 50%.
        $producer = $this->interactor->beginCurrentTransactionFromHeaders('POST /orders', 'request', [
            'traceparent' => '00-'.self::UPSTREAM_TRACE_ID.'-'.self::UPSTREAM_PARENT_ID.'-01',
            'tracestate' => 'es=s:0.5',
        ]);
        $dispatchSpan = $producer->beginCurrentSpan('dispatch orders', 'app');
        $bus->dispatch(new TestMessage('first'));
        $bus->dispatch(new TestMessage('second'));
        $dispatchSpan->end();
        $producer->end();

        $this->assertCount(2, $this->transport->getSent());

        foreach ($this->transport->getSent() as $sent) {
            $this->assertSame('es=s:0.5', $sent->last(ApmTracingStamp::class)?->getHeader('tracestate'));
        }

        // The agent's own transaction for the `messenger:consume` process.
        ElasticApm::beginCurrentTransaction('messenger:consume', 'cli');
        $this->runWorker($bus, 2);

        $this->assertTrue(ElasticApm::getCurrentTransaction()->isNoop(), 'A transaction is still current');

        [$producerEvent, $first, $second] = $this->transactionsByName(
            'POST /orders',
            'messenger:consume '.self::TRANSPORT.' '.TestMessage::class,
        );
        $this->assertCount(1, $this->sink->spans);
        $dispatchSpanEvent = $this->sink->spans[0];
        $this->assertSame('dispatch orders', $dispatchSpanEvent['name']);

        foreach ([$first, $second] as $message) {
            $this->assertSame($producerEvent['trace_id'], $message['trace_id']);
            $this->assertSame(self::UPSTREAM_TRACE_ID, $message['trace_id']);
            $this->assertSame($dispatchSpanEvent['id'], $message['parent_id']);
            $this->assertEqualsWithDelta(0.5, $message['sample_rate'], 0.0001);
            $this->assertSame('message', $message['type']);
            $this->assertSame('MSG 2xx', $message['result']);
            $this->assertSame('success', $message['outcome']);
        }

        $this->assertNotSame($first['id'], $second['id']);
        // Each handler ran inside its own message's transaction.
        $this->assertSame([$first['id'], $second['id']], $this->handledIn);
    }

    public function testProcessTransactionOfAnIdleWorkerIsNeverSent(): void
    {
        $bus = $this->createBus();
        ElasticApm::beginCurrentTransaction('messenger:consume', 'cli');

        $this->dispatcher->addListener(
            WorkerRunningEvent::class,
            static fn(WorkerRunningEvent $event) => $event->getWorker()->stop(),
        );
        $this->runWorker($bus, 1);

        $this->assertSame([], $this->sink->transactions);
        // Agent 1.15 keeps a discarded transaction current, but it has ended and so is never propagated.
        $current = ElasticApm::getCurrentTransaction();
        $this->assertTrue($current->isNoop() || $current->hasEnded());
    }

    public function testMessageDispatchedFromAConsumedMessageStaysInTheTrace(): void
    {
        $bus = $this->createBus(static function (TestMessage $message, MessageBus $bus): void {
            if ('parent' === $message->payload) {
                $bus->dispatch(new TestMessage('child'));
            }
        });

        ElasticApm::beginCurrentTransaction('messenger:consume', 'cli');
        // Dispatched outside any transaction (e.g. by a scheduler), so the first message starts a new trace.
        $this->transport->send(new Envelope(new TestMessage('parent')));
        $this->runWorker($bus, 2);

        $this->assertCount(2, $this->sink->transactions);
        [$parent, $child] = $this->sink->transactions;
        $this->assertArrayNotHasKey('parent_id', $parent);
        $this->assertSame($parent['trace_id'], $child['trace_id']);
        $this->assertSame($parent['id'], $child['parent_id']);
        $this->assertTrue(ElasticApm::getCurrentTransaction()->isNoop());
    }

    /**
     * @param (\Closure(TestMessage, MessageBus): void)|null $handler
     */
    private function createBus(?\Closure $handler = null): MessageBus
    {
        $senders = new SendersLocator(
            [TestMessage::class => [self::TRANSPORT]],
            new ServiceLocator([self::TRANSPORT => fn(): InMemoryTransport => $this->transport]),
        );

        $bus = null;
        $handle = function (TestMessage $message) use (&$bus, $handler): void {
            $this->handledIn[] = ElasticApm::getCurrentTransaction()->getId();

            if (null !== $handler && $bus instanceof MessageBus) {
                $handler($message, $bus);
            }
        };

        $bus = new MessageBus([
            new SendMessageMiddleware($senders, $this->dispatcher),
            new HandleMessageMiddleware(new HandlersLocator([TestMessage::class => [$handle]])),
        ]);

        return $bus;
    }

    private function runWorker(MessageBus $bus, int $messages): void
    {
        $this->dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($messages));
        $this->dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(5));

        (new Worker([self::TRANSPORT => $this->transport], $bus, $this->dispatcher))->run(['sleep' => 1000]);
    }

    /**
     * @return list<array<string, mixed>> the transaction named $producer, then every transaction named $consumer
     */
    private function transactionsByName(string $producer, string $consumer): array
    {
        $names = \array_column($this->sink->transactions, 'name');
        $this->assertSame([$producer, $consumer, $consumer], $names);

        return $this->sink->transactions;
    }
}

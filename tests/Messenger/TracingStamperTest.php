<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Tests\Messenger\Fixtures\CreatesTransactionSpies;
use ElasticApmBundle\Tests\Messenger\Fixtures\RecordingInteractor;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(TracingStamper::class)]
final class TracingStamperTest extends TestCase
{
    use CreatesTransactionSpies;

    public function testStampsEnvelopeWithCurrentTransactionHeaders(): void
    {
        $headers = ['traceparent' => self::PRODUCER_TRACEPARENT, 'tracestate' => 'es=s:1'];
        $stamper = $this->createStamper($this->createTransactionSpy($headers)->transaction);

        $envelope = $stamper->stamp(new Envelope(new TestMessage()));

        $this->assertSame($headers, $envelope->last(ApmTracingStamp::class)?->getHeaders());
    }

    public function testStampsEnvelopeWithActiveSpanHeadersSoTheConsumerIsItsChild(): void
    {
        $spanHeaders = ['traceparent' => '00-'.self::PRODUCER_TRACE_ID.'-00f067aa0ba902b7-01', 'tracestate' => 'es=s:1'];
        $transaction = $this->createTransactionSpy(currentSpan: $this->createSpan($spanHeaders))->transaction;

        $envelope = $this->createStamper($transaction)->stamp(new Envelope(new TestMessage()));

        $this->assertSame($spanHeaders, $envelope->last(ApmTracingStamp::class)?->getHeaders());
    }

    public function testFallsBackToTransactionWhenActiveSpanHasEnded(): void
    {
        $span = $this->createSpan(['traceparent' => 'ended span'], ended: true);
        $transaction = $this->createTransactionSpy(currentSpan: $span)->transaction;

        $envelope = $this->createStamper($transaction)->stamp(new Envelope(new TestMessage()));

        $this->assertSame(
            ['traceparent' => self::PRODUCER_TRACEPARENT],
            $envelope->last(ApmTracingStamp::class)?->getHeaders(),
        );
    }

    // After the worker discards its process transaction, the agent may still report it as current.
    public function testDoesNotStampFromAnEndedTransaction(): void
    {
        $spy = $this->createTransactionSpy();
        $spy->transaction->discard();
        $envelope = new Envelope(new TestMessage());

        $this->assertSame($envelope, $this->createStamper($spy->transaction)->stamp($envelope));
    }

    public function testPassesThroughWithoutCurrentTransaction(): void
    {
        $envelope = new Envelope(new TestMessage());

        $this->assertSame($envelope, $this->createStamper(null)->stamp($envelope));
    }

    public function testDoesNotStampFromANoopTransaction(): void
    {
        $envelope = new Envelope(new TestMessage());
        $stamper = $this->createStamper($this->createTransactionSpy(noop: true)->transaction);

        $this->assertSame($envelope, $stamper->stamp($envelope));
    }

    public function testDoesNotAddEmptyStampWhenTransactionInjectsNothing(): void
    {
        $envelope = new Envelope(new TestMessage());
        $stamper = $this->createStamper($this->createTransactionSpy([])->transaction);

        $this->assertSame($envelope, $stamper->stamp($envelope));
    }

    public function testKeepsExistingStamp(): void
    {
        $original = new ApmTracingStamp(['traceparent' => 'original']);
        $envelope = new Envelope(new TestMessage(), [$original]);

        $stamped = $this->createStamper($this->createTransactionSpy()->transaction)->stamp($envelope);

        $this->assertSame($envelope, $stamped);
        $this->assertCount(1, $stamped->all(ApmTracingStamp::class));
    }

    public function testDoesNotStampMessagesConsumedByWorker(): void
    {
        $envelope = new Envelope(new TestMessage(), [new ConsumedByWorkerStamp()]);

        $this->assertSame(
            $envelope,
            $this->createStamper($this->createTransactionSpy()->transaction)->stamp($envelope)
        );
    }

    public function testDoesNotStampReceivedMessages(): void
    {
        $envelope = new Envelope(new TestMessage(), [new ReceivedStamp('async')]);

        $this->assertSame(
            $envelope,
            $this->createStamper($this->createTransactionSpy()->transaction)->stamp($envelope)
        );
    }

    private function createStamper(?\Elastic\Apm\TransactionInterface $current): TracingStamper
    {
        return new TracingStamper(new RecordingInteractor(static fn(): null => null, $current));
    }
}

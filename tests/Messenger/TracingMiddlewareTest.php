<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use Elastic\Apm\TransactionInterface;
use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Messenger\TracingMiddleware;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Tests\Messenger\Fixtures\CreatesTransactionSpies;
use ElasticApmBundle\Tests\Messenger\Fixtures\RecordingInteractor;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    use CreatesTransactionSpies;

    public function testStampsEnvelopeBeforePassingItOn(): void
    {
        $seen = $this->handle(new Envelope(new TestMessage()), $this->createTransactionSpy()->transaction);

        $this->assertSame(
            ['traceparent' => self::PRODUCER_TRACEPARENT],
            $seen->last(ApmTracingStamp::class)?->getHeaders(),
        );
    }

    public function testPassesThroughWhenThereIsNoTransaction(): void
    {
        $envelope = new Envelope(new TestMessage());

        $this->assertSame($envelope, $this->handle($envelope, null));
    }

    public function testDoesNotRestampConsumedMessages(): void
    {
        $original = new ApmTracingStamp(['traceparent' => 'from-producer']);
        $envelope = new Envelope(
            new TestMessage(),
            [$original, new ReceivedStamp('async'), new ConsumedByWorkerStamp()],
        );

        $seen = $this->handle($envelope, $this->createTransactionSpy()->transaction);

        $this->assertSame($envelope, $seen);
        $this->assertSame([$original], $seen->all(ApmTracingStamp::class));
    }

    public function testDoesNotStampConsumedMessagesWithoutStamp(): void
    {
        $envelope = new Envelope(new TestMessage(), [new ConsumedByWorkerStamp()]);

        $this->assertSame($envelope, $this->handle($envelope, $this->createTransactionSpy()->transaction));
    }

    private function handle(Envelope $envelope, ?TransactionInterface $current): Envelope
    {
        $middleware = new TracingMiddleware(
            new TracingStamper(new RecordingInteractor(static fn() => null, $current)),
        );

        $next = new class implements MiddlewareInterface {
            public ?Envelope $seen = null;

            #[\Override]
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->seen = $envelope;

                return $envelope;
            }
        };

        $result = $middleware->handle($envelope, new StackMiddleware($next));
        $this->assertSame($next->seen, $result);

        return $result;
    }
}

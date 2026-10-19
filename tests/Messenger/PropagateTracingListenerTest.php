<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Messenger\PropagateTracingListener;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Tests\Messenger\Fixtures\CreatesTransactionSpies;
use ElasticApmBundle\Tests\Messenger\Fixtures\RecordingInteractor;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

#[CoversClass(PropagateTracingListener::class)]
final class PropagateTracingListenerTest extends TestCase
{
    use CreatesTransactionSpies;

    public function testSubscribesToSendMessageToTransports(): void
    {
        $this->assertSame(
            [SendMessageToTransportsEvent::class => ['onSendMessageToTransports', 0]],
            PropagateTracingListener::getSubscribedEvents(),
        );
    }

    public function testReplacesEnvelopeWithStampedOne(): void
    {
        $listener = $this->createListener($this->createTransactionSpy()->transaction);
        $event = new SendMessageToTransportsEvent(new Envelope(new TestMessage()), []);

        $listener->onSendMessageToTransports($event);

        $this->assertSame(
            ['traceparent' => self::PRODUCER_TRACEPARENT],
            $event->getEnvelope()->last(ApmTracingStamp::class)?->getHeaders(),
        );
    }

    public function testLeavesEnvelopeUntouchedWithoutTransaction(): void
    {
        $envelope = new Envelope(new TestMessage());
        $event = new SendMessageToTransportsEvent($envelope, []);

        $this->createListener(null)->onSendMessageToTransports($event);

        $this->assertSame($envelope, $event->getEnvelope());
    }

    private function createListener(?\Elastic\Apm\TransactionInterface $current): PropagateTracingListener
    {
        return new PropagateTracingListener(new TracingStamper(new RecordingInteractor(static fn(): null => null, $current)));
    }
}

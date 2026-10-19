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

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Adds an {@see ApmTracingStamp} carrying the active span's (or transaction's) distributed tracing headers to
 * outgoing envelopes.
 */
final readonly class TracingStamper
{
    public function __construct(
        private ElasticApmInteractorInterface $interactor,
    ) {}

    public function stamp(Envelope $envelope): Envelope
    {
        // An existing stamp links the message to the trace that first produced it, which must survive re-sends;
        // received envelopes are being consumed, not produced, so they have nothing to propagate.
        if (
            null !== $envelope->last(ApmTracingStamp::class)
            || null !== $envelope->last(ConsumedByWorkerStamp::class)
            || null !== $envelope->last(ReceivedStamp::class)
        ) {
            return $envelope;
        }

        $transaction = $this->interactor->getCurrentTransaction();

        // An ended transaction can still be the agent's current one (e.g. the discarded worker process transaction).
        if (null === $transaction || $transaction->isNoop() || $transaction->hasEnded()) {
            return $envelope;
        }

        // The consumer's transaction must be a child of whatever is active when the message is sent.
        $span = $transaction->getCurrentSpan();
        $segment = $span->isNoop() || $span->hasEnded() ? $transaction : $span;

        $headers = [];
        $segment->injectDistributedTracingHeaders(
            static function (string $name, string $value) use (&$headers): void {
                $headers[$name] = $value;
            },
        );

        // Nothing to propagate, e.g. the transaction is not sampled by a disabled agent.
        if ([] === $headers) {
            return $envelope;
        }

        return $envelope->with(new ApmTracingStamp($headers));
    }
}

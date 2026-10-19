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

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Only needed for buses whose `SendMessageMiddleware` has no event dispatcher; otherwise
 * {@see PropagateTracingListener} already stamps every message sent to a transport.
 */
final readonly class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TracingStamper $stamper,
    ) {}

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $stack->next()->handle($this->stamper->stamp($envelope), $stack);
    }
}

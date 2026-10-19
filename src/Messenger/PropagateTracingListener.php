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

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

/**
 * Stamps every message right before it is sent to a transport, whatever bus it was dispatched on.
 */
final readonly class PropagateTracingListener implements EventSubscriberInterface
{
    public function __construct(
        private TracingStamper $stamper,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => ['onSendMessageToTransports', 0],
        ];
    }

    public function onSendMessageToTransports(SendMessageToTransportsEvent $event): void
    {
        $event->setEnvelope($this->stamper->stamp($event->getEnvelope()));
    }
}

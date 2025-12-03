<?php

declare(strict_types=1);

/*
 * This file is part of Ekino New Relic bundle.
 *
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\Listener;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listen to exceptions dispatched by Symfony to log them to Elastic APM.
 */
final readonly class ExceptionListener implements EventSubscriberInterface
{
    /**
     * @param array<class-string> $ignoredExceptions
     */
    public function __construct(
        private ElasticApmInteractorInterface $interactor,
        private array $ignoredExceptions,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface && !\in_array($exception::class, $this->ignoredExceptions)) {
            $this->interactor->addContextFromConfig();
            $this->interactor->noticeThrowable($exception);
        }
    }
}

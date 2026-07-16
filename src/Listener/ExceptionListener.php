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

namespace ElasticApmBundle\Listener;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
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
     * @param int                 $captureMinStatusCode minimum HTTP status code an HttpException must carry to be reported
     */
    public function __construct(
        private ElasticApmInteractorInterface $interactor,
        private array $ignoredExceptions,
        private int $captureMinStatusCode = Response::HTTP_INTERNAL_SERVER_ERROR,
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

        if ($this->isIgnored($exception)) {
            return;
        }

        // HTTP errors below the configured threshold (500 by default) are expected client-side
        // noise; 5xx errors are genuine server failures and must reach APM. Lower the threshold
        // via exceptions.capture_min_status_code to also capture 4xx signal.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < $this->captureMinStatusCode) {
            return;
        }

        $this->interactor->addContextFromConfig();
        $this->interactor->noticeThrowable($exception);
    }

    private function isIgnored(\Throwable $exception): bool
    {
        foreach ($this->ignoredExceptions as $ignoredException) {
            // Use instanceof so subclasses of an ignored exception are ignored too.
            if ($exception instanceof $ignoredException) {
                return true;
            }
        }

        return false;
    }
}

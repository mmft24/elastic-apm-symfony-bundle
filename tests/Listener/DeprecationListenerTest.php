<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Exception\DeprecationException;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\AbstractErrorHandlerListener;
use ElasticApmBundle\Listener\DeprecationListener;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeprecationListener::class)]
#[CoversClass(AbstractErrorHandlerListener::class)]
final class DeprecationListenerTest extends ErrorHandlerListenerTestCase
{
    public function testWarningsAreNotReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->never())->method('noticeThrowable');

        \trigger_error('warning', \E_USER_WARNING);
    }

    #[\Override]
    protected function createListener(ElasticApmInteractorInterface $interactor): AbstractErrorHandlerListener
    {
        return new DeprecationListener($interactor);
    }

    #[\Override]
    protected function createFilteringListener(ElasticApmInteractorInterface $interactor): AbstractErrorHandlerListener
    {
        return new class($interactor) extends DeprecationListener {
            #[\Override]
            protected function shouldReport(int $type, string $message, string $file, int $line): bool
            {
                return !\str_contains($message, 'ignored') && parent::shouldReport($type, $message, $file, $line);
            }
        };
    }

    #[\Override]
    protected function reportedLevel(): int
    {
        return \E_USER_DEPRECATED;
    }

    #[\Override]
    protected function ignoredLevel(): int
    {
        return \E_USER_NOTICE;
    }

    #[\Override]
    protected function throwableClass(): string
    {
        return DeprecationException::class;
    }
}

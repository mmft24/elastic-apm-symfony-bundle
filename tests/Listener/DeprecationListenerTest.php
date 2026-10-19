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

    public function testTriggerDeprecationIsReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())->method('addContextFromConfig');
        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(static fn(\Throwable $e): bool => $e instanceof DeprecationException
                && \E_USER_DEPRECATED === $e->getSeverity()
                && 'Since acme/package 1.2: "old()" is deprecated, use "new()".' === $e->getMessage()));

        // symfony/deprecation-contracts emits the deprecation with "@", like doctrine/deprecations does.
        \trigger_deprecation('acme/package', '1.2', '"%s" is deprecated, use "%s".', 'old()', 'new()');

        $this->assertCount(1, $this->baseCalls);
    }

    public function testAtSilencedDeprecationIsReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        @\trigger_error('silenced', \E_USER_DEPRECATED);

        $this->assertSame([[\E_USER_DEPRECATED, 'silenced']], $this->baseCallsWithoutLocation());
    }

    public function testDeprecationExcludedByErrorReportingIsReported(): void
    {
        $this->listener->register();

        // Symfony's ErrorHandler never treats deprecations as silenced, not even when error_reporting() excludes them.
        $this->interactor->expects($this->exactly(2))->method('noticeThrowable');

        \error_reporting(0);
        \trigger_error('excluded by zero', \E_USER_DEPRECATED);
        \error_reporting(\E_ALL & ~\E_USER_DEPRECATED);
        \trigger_error('excluded by mask', \E_USER_DEPRECATED);

        $this->assertCount(2, $this->baseCalls);
    }

    public function testSilencedAndLoudDeprecationAtTheSameSiteAreDeduplicated(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())->method('noticeThrowable');

        for ($i = 0; $i < 2; ++$i) {
            0 === $i ? @\trigger_error('same site', \E_USER_DEPRECATED) : \trigger_error(
                'same site',
                \E_USER_DEPRECATED
            );
        }
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

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Exception\WarningException;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\AbstractErrorHandlerListener;
use ElasticApmBundle\Listener\WarningListener;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WarningListener::class)]
#[CoversClass(AbstractErrorHandlerListener::class)]
final class WarningListenerTest extends ErrorHandlerListenerTestCase
{
    public function testEngineWarningIsReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($this->callback(static fn(\Throwable $e): bool => $e instanceof WarningException
                && \E_WARNING === $e->getSeverity()
                && \str_contains($e->getMessage(), 'Undefined array key')));

        $this->assertNull($this->readMissingKey([]));
    }

    public function testAtSilencedEngineWarningIsNotReported(): void
    {
        $this->listener->register();

        $this->interactor->expects($this->never())->method('noticeThrowable');

        $this->assertNull(@$this->readMissingKey([]));

        $this->assertCount(1, $this->baseCalls);
    }

    #[\Override]
    protected function createListener(ElasticApmInteractorInterface $interactor): AbstractErrorHandlerListener
    {
        return new WarningListener($interactor);
    }

    #[\Override]
    protected function createFilteringListener(ElasticApmInteractorInterface $interactor): AbstractErrorHandlerListener
    {
        return new class($interactor) extends WarningListener {
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
        return \E_USER_WARNING;
    }

    #[\Override]
    protected function ignoredLevel(): int
    {
        return \E_USER_NOTICE;
    }

    #[\Override]
    protected function throwableClass(): string
    {
        return WarningException::class;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function readMissingKey(array $array): mixed
    {
        return $array['missing'];
    }
}

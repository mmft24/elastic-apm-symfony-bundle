<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Interactor\LoggingInteractorDecorator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(LoggingInteractorDecorator::class)]
final class LoggingInteractorDecoratorTest extends TestCase
{
    private ElasticApmInteractorInterface&\PHPUnit\Framework\MockObject\MockObject $interactor;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private LoggingInteractorDecorator $decorator;

    public function testConstructorWithoutLoggerUsesNullLogger(): void
    {
        $interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $decorator = new LoggingInteractorDecorator($interactor);

        // Should not throw any errors
        $interactor->expects($this->once())
            ->method('setTransactionName')
            ->with('test')
            ->willReturn(true);

        $result = $decorator->setTransactionName('test');
        $this->assertTrue($result);
    }

    public function testSetTransactionNameLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Setting Elastic APM transaction name to {name}',
                ['name' => 'test-transaction'],
            );

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
            ->with('test-transaction')
            ->willReturn(true);

        $result = $this->decorator->setTransactionName('test-transaction');

        $this->assertTrue($result);
    }

    public function testAddLabelLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Adding Elastic APM label {name}',
                ['name' => 'key'],
            );

        $this->interactor->expects($this->once())
            ->method('addLabel')
            ->with('key', 'value')
            ->willReturn(true);

        $result = $this->decorator->addLabel('key', 'value');

        $this->assertTrue($result);
    }

    public function testAddCustomContextLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Adding Elastic APM custom context {name}',
                ['name' => 'key'],
            );

        $this->interactor->expects($this->once())
            ->method('addCustomContext')
            ->with('key', 'value')
            ->willReturn(true);

        $result = $this->decorator->addCustomContext('key', 'value');

        $this->assertTrue($result);
    }

    public function testNoticeThrowableLogsAndDelegates(): void
    {
        $this->createDecorator();

        $exception = new \RuntimeException('Test exception');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Sending exception to Elastic APM',
                $this->callback(fn($context): bool => isset($context['exception'])
                    && $context['exception'] === $exception
                    && isset($context['message'])
                    && 'Test exception' === $context['message']),
            );

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $this->decorator->noticeThrowable($exception);
    }

    public function testBeginTransactionLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Starting a new Elastic APM transaction for app {name}',
                ['name' => 'test-app'],
            );

        $this->interactor->expects($this->once())
            ->method('beginTransaction')
            ->with('test-app', 'request', null, null)
            ->willReturn(null);

        $result = $this->decorator->beginTransaction('test-app', 'request');

        $this->assertNull($result);
    }

    public function testBeginCurrentTransactionLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Starting a new Elastic APM transaction and setting to current for app {name}',
                ['name' => 'test-app'],
            );

        $this->interactor->expects($this->once())
            ->method('beginCurrentTransaction')
            ->with('test-app', 'request', null, null)
            ->willReturn(null);

        $result = $this->decorator->beginCurrentTransaction('test-app', 'request');

        $this->assertNull($result);
    }

    public function testEndCurrentTransactionLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Ending the current Elastic APM transaction');

        $this->interactor->expects($this->once())
            ->method('endCurrentTransaction')
            ->with(null)
            ->willReturn(true);

        $result = $this->decorator->endCurrentTransaction();

        $this->assertTrue($result);
    }

    public function testGetCurrentTransactionLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Getting active transaction');

        $this->interactor->expects($this->once())
            ->method('getCurrentTransaction')
            ->willReturn(null);

        $result = $this->decorator->getCurrentTransaction();

        $this->assertNull($result);
    }

    public function testBeginCurrentSpanLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Starting new span on current transaction and making it current');

        $this->interactor->expects($this->once())
            ->method('beginCurrentSpan')
            ->with('span-name', 'type', null, null, null)
            ->willReturn(null);

        $result = $this->decorator->beginCurrentSpan('span-name', 'type');

        $this->assertNull($result);
    }

    public function testEndCurrentSpanLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Ending current span on active transaction');

        $this->interactor->expects($this->once())
            ->method('endCurrentSpan')
            ->with(null)
            ->willReturn(true);

        $result = $this->decorator->endCurrentSpan();

        $this->assertTrue($result);
    }

    public function testCaptureCurrentSpanLogsAndDelegates(): void
    {
        $this->createDecorator();

        $callback = fn(): string => 'result';

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Starting new span capture');

        $this->interactor->expects($this->once())
            ->method('captureCurrentSpan')
            ->with('name', 'type', $callback, null, null, null)
            ->willReturn('result');

        $result = $this->decorator->captureCurrentSpan('name', 'type', $callback);

        $this->assertSame('result', $result);
    }

    public function testSetUserAttributesLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Setting Elastic APM user attributes');

        $this->interactor->expects($this->once())
            ->method('setUserAttributes')
            ->with('id', 'email@test.com', 'username')
            ->willReturn(true);

        $result = $this->decorator->setUserAttributes('id', 'email@test.com', 'username');

        $this->assertTrue($result);
    }

    public function testAddContextFromConfigLogsAndDelegates(): void
    {
        $this->createDecorator();

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Adding context from config');

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig');

        $this->decorator->addContextFromConfig();
    }

    private function createDecorator(): void
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->decorator = new LoggingInteractorDecorator($this->interactor, $this->logger);
    }
}

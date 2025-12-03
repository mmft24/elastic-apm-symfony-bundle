<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use ElasticApmBundle\Interactor\AdaptiveInteractor;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdaptiveInteractor::class)]
final class AdaptiveInteractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (\extension_loaded('elastic_apm') && \class_exists(\Elastic\Apm\ElasticApm::class)) {
            $this->markTestSkipped('Elastic APM extension is loaded');
        }

        parent::setUp();
    }

    public function testUsesCorrectInteractorBasedOnExtension(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        // Since we can't control extension_loaded(), we test that one of them is called
        $extensionLoaded = \extension_loaded('elastic_apm') && \class_exists(\Elastic\Apm\ElasticApm::class, false);

        if ($extensionLoaded) {
            $realInteractor->expects($this->once())
                ->method('setTransactionName')
                ->with('test-transaction')
                ->willReturn(true);
            $fakeInteractor->expects($this->never())
                ->method('setTransactionName');
        } else {
            $fakeInteractor->expects($this->once())
                ->method('setTransactionName')
                ->with('test-transaction')
                ->willReturn(true);
            $realInteractor->expects($this->never())
                ->method('setTransactionName');
        }

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->setTransactionName('test-transaction');
        $this->assertTrue($result);
    }

    public function testDelegatesSetTransactionName(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('setTransactionName')
            ->with('transaction')
            ->willReturn(true);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->setTransactionName('transaction');

        $this->assertTrue($result);
    }

    public function testDelegatesAddLabel(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('addLabel')
            ->with('key', 'value')
            ->willReturn(true);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->addLabel('key', 'value');

        $this->assertTrue($result);
    }

    public function testDelegatesAddCustomContext(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('addCustomContext')
            ->with('key', 'value')
            ->willReturn(true);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->addCustomContext('key', 'value');

        $this->assertTrue($result);
    }

    public function testDelegatesNoticeThrowable(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $exception = new \RuntimeException('Test');

        $fakeInteractor->expects($this->once())
            ->method('noticeThrowable')
            ->with($exception);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $interactor->noticeThrowable($exception);
    }

    public function testDelegatesBeginTransaction(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('beginTransaction')
            ->with('name', 'type', null, null)
            ->willReturn(null);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->beginTransaction('name', 'type');

        $this->assertNull($result);
    }

    public function testDelegatesBeginCurrentTransaction(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('beginCurrentTransaction')
            ->with('name', 'type', null, null)
            ->willReturn(null);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->beginCurrentTransaction('name', 'type');

        $this->assertNull($result);
    }

    public function testDelegatesEndCurrentTransaction(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('endCurrentTransaction')
            ->with(null)
            ->willReturn(true);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->endCurrentTransaction();

        $this->assertTrue($result);
    }

    public function testDelegatesGetCurrentTransaction(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('getCurrentTransaction')
            ->willReturn(null);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->getCurrentTransaction();

        $this->assertNull($result);
    }

    public function testDelegatesEndCurrentSpan(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('endCurrentSpan')
            ->with(null)
            ->willReturn(true);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->endCurrentSpan();

        $this->assertTrue($result);
    }

    public function testDelegatesCaptureCurrentSpan(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $callback = fn(): string => 'result';

        $fakeInteractor->expects($this->once())
            ->method('captureCurrentSpan')
            ->with('name', 'type', $callback, null, null, null)
            ->willReturn('result');

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->captureCurrentSpan('name', 'type', $callback);

        $this->assertSame('result', $result);
    }

    public function testDelegatesSetUserAttributes(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('setUserAttributes')
            ->with('id', 'email@test.com', 'username')
            ->willReturn(true);

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $result = $interactor->setUserAttributes('id', 'email@test.com', 'username');

        $this->assertTrue($result);
    }

    public function testDelegatesAddContextFromConfig(): void
    {
        $realInteractor = $this->createMock(ElasticApmInteractorInterface::class);
        $fakeInteractor = $this->createMock(ElasticApmInteractorInterface::class);

        $fakeInteractor->expects($this->once())
            ->method('addContextFromConfig');

        $interactor = new AdaptiveInteractor($realInteractor, $fakeInteractor);
        $interactor->addContextFromConfig();
    }
}

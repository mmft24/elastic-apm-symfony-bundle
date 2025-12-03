<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use ElasticApmBundle\Interactor\BlackholeInteractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlackholeInteractor::class)]
final class BlackholeInteractorTest extends TestCase
{
    private BlackholeInteractor $interactor;

    protected function setUp(): void
    {
        $this->interactor = new BlackholeInteractor();
    }

    public function testSetTransactionNameReturnsTrue(): void
    {
        $result = $this->interactor->setTransactionName('test-transaction');

        $this->assertTrue($result);
    }

    public function testAddLabelReturnsTrue(): void
    {
        $result = $this->interactor->addLabel('key', 'value');

        $this->assertTrue($result);
    }

    public function testAddCustomContextReturnsTrue(): void
    {
        $result = $this->interactor->addCustomContext('key', 'value');

        $this->assertTrue($result);
    }

    public function testNoticeThrowableDoesNothing(): void
    {
        $exception = new \RuntimeException('Test exception');

        // Should not throw any exception
        $this->interactor->noticeThrowable($exception);

        $this->expectNotToPerformAssertions();
    }

    public function testBeginTransactionReturnsNull(): void
    {
        $result = $this->interactor->beginTransaction('name', 'type');

        $this->assertNull($result);
    }

    public function testBeginCurrentTransactionReturnsNull(): void
    {
        $result = $this->interactor->beginCurrentTransaction('name', 'type');

        $this->assertNull($result);
    }

    public function testEndCurrentTransactionReturnsTrue(): void
    {
        $result = $this->interactor->endCurrentTransaction();

        $this->assertTrue($result);
    }

    public function testGetCurrentTransactionReturnsNull(): void
    {
        $result = $this->interactor->getCurrentTransaction();

        $this->assertNull($result);
    }

    public function testBeginCurrentSpanReturnsNull(): void
    {
        $result = $this->interactor->beginCurrentSpan('span-name', 'span-type');

        $this->assertNull($result);
    }

    public function testEndCurrentSpanReturnsTrue(): void
    {
        $result = $this->interactor->endCurrentSpan();

        $this->assertTrue($result);
    }

    public function testCaptureCurrentSpanExecutesCallback(): void
    {
        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return 'callback-result';
        };

        $result = $this->interactor->captureCurrentSpan('span-name', 'span-type', $callback);

        $this->assertTrue($callbackExecuted);
        $this->assertSame('callback-result', $result);
    }

    public function testSetUserAttributesReturnsTrue(): void
    {
        $result = $this->interactor->setUserAttributes('user-id', 'user@example.com', 'username');

        $this->assertTrue($result);
    }

    public function testAddContextFromConfigDoesNothing(): void
    {
        // Should not throw any exception
        $this->interactor->addContextFromConfig();

        $this->expectNotToPerformAssertions();
    }
}

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(ElasticApmInteractor::class)]
#[RequiresPhpExtension('elastic_apm')]
final class ElasticApmInteractorTest extends TestCase
{
    private Config $config;
    private ElasticApmInteractor $interactor;

    protected function setUp(): void
    {
        if (!\extension_loaded('elastic_apm') || !\class_exists(\Elastic\Apm\ElasticApm::class)) {
            $this->markTestSkipped('Elastic APM extension is not loaded');
        }

        $this->config = new Config(
            customLabels: ['custom_label' => 'value'],
            customContext: ['custom_context' => 'ctx_value'],
            shouldCollectMemoryUsage: true,
            memoryUsageLabelName: 'memory_peak',
            shouldExplicitlyCollectCommandExceptions: false,
            shouldUnwrapExceptions: true,
        );
        $this->interactor = new ElasticApmInteractor($this->config);
    }

    public function testSetTransactionName(): void
    {
        $result = $this->interactor->setTransactionName('test-transaction');
        $this->assertTrue($result);
    }

    public function testAddLabel(): void
    {
        $result = $this->interactor->addLabel('test-key', 'test-value');
        $this->assertTrue($result);
    }

    public function testAddLabelTruncatesLongKeys(): void
    {
        $longKey = \str_repeat('a', 2000);
        $result = $this->interactor->addLabel($longKey, 'value');
        $this->assertTrue($result);
    }

    public function testAddLabelTruncatesLongStringValues(): void
    {
        $longValue = \str_repeat('b', 2000);
        $result = $this->interactor->addLabel('key', $longValue);
        $this->assertTrue($result);
    }

    public function testAddLabelWithNonStringValue(): void
    {
        $result = $this->interactor->addLabel('int-value', 42);
        $this->assertTrue($result);

        $result = $this->interactor->addLabel('float-value', 3.14);
        $this->assertTrue($result);

        $result = $this->interactor->addLabel('bool-value', true);
        $this->assertTrue($result);
    }

    public function testAddCustomContext(): void
    {
        $result = $this->interactor->addCustomContext('context-key', 'context-value');
        $this->assertTrue($result);
    }

    public function testNoticeThrowable(): void
    {
        $exception = new \RuntimeException('Test exception');
        $this->interactor->noticeThrowable($exception);

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }

    public function testNoticeThrowableWithPrevious(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new \RuntimeException('Test exception', 0, $previous);

        $this->interactor->noticeThrowable($exception);

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }

    public function testNoticeThrowableWithoutUnwrapping(): void
    {
        $config = new Config(
            customLabels: [],
            customContext: [],
            shouldCollectMemoryUsage: false,
            memoryUsageLabelName: 'memory',
            shouldExplicitlyCollectCommandExceptions: false,
            shouldUnwrapExceptions: false,
        );
        $interactor = new ElasticApmInteractor($config);

        $previous = new \RuntimeException('Previous exception');
        $exception = new \RuntimeException('Test exception', 0, $previous);

        $interactor->noticeThrowable($exception);

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }

    public function testEndCurrentTransaction(): void
    {
        $this->interactor->beginCurrentTransaction('test', 'request');
        $result = $this->interactor->endCurrentTransaction();
        $this->assertTrue($result);
    }

    public function testEndCurrentTransactionWithDuration(): void
    {
        $this->interactor->beginCurrentTransaction('test', 'request');
        $result = $this->interactor->endCurrentTransaction(1.5);
        $this->assertTrue($result);
    }

    public function testEndCurrentSpan(): void
    {
        $this->interactor->beginCurrentTransaction('test', 'request');
        $this->interactor->beginCurrentSpan('test-span', 'db');
        $result = $this->interactor->endCurrentSpan();
        $this->assertTrue($result);
    }

    public function testEndCurrentSpanWithDuration(): void
    {
        $this->interactor->beginCurrentTransaction('test', 'request');
        $this->interactor->beginCurrentSpan('test-span', 'db');
        $result = $this->interactor->endCurrentSpan(0.5);
        $this->assertTrue($result);
    }

    public function testCaptureCurrentSpan(): void
    {
        $this->interactor->beginCurrentTransaction('test', 'request');
        $result = $this->interactor->captureCurrentSpan(
            'test-span',
            'db',
            fn(): string => 'test-result',
        );
        $this->assertSame('test-result', $result);
    }

    public function testCaptureCurrentSpanWithAllParameters(): void
    {
        $this->interactor->beginCurrentTransaction('test', 'request');
        $result = $this->interactor->captureCurrentSpan(
            'test-span',
            'db',
            fn(): int => 42,
            'mysql',
            'query',
            microtime(true),
        );
        $this->assertSame(42, $result);
    }

    public function testSetUserAttributes(): void
    {
        $result = $this->interactor->setUserAttributes('user-123', 'user@example.com', 'testuser');
        $this->assertTrue($result);
    }

    public function testSetUserAttributesWithOnlyId(): void
    {
        $result = $this->interactor->setUserAttributes('user-123', null, null);
        $this->assertTrue($result);
    }

    public function testSetUserAttributesWithOnlyEmail(): void
    {
        $result = $this->interactor->setUserAttributes(null, 'user@example.com', null);
        $this->assertTrue($result);
    }

    public function testSetUserAttributesWithOnlyUsername(): void
    {
        $result = $this->interactor->setUserAttributes(null, null, 'testuser');
        $this->assertTrue($result);
    }

    public function testSetUserAttributesWithAllNull(): void
    {
        $result = $this->interactor->setUserAttributes(null, null, null);
        $this->assertTrue($result);
    }

    public function testAddContextFromConfig(): void
    {
        $this->interactor->addContextFromConfig();

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }

    public function testAddContextFromConfigWithMemoryUsage(): void
    {
        $config = new Config(
            customLabels: ['label1' => 'value1'],
            customContext: ['context1' => 'ctx1'],
            shouldCollectMemoryUsage: true,
            memoryUsageLabelName: 'memory_usage',
            shouldExplicitlyCollectCommandExceptions: false,
            shouldUnwrapExceptions: false,
        );
        $interactor = new ElasticApmInteractor($config);

        $interactor->addContextFromConfig();

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }

    public function testAddContextFromConfigWithoutMemoryUsage(): void
    {
        $config = new Config(
            customLabels: ['label1' => 'value1'],
            customContext: ['context1' => 'ctx1'],
            shouldCollectMemoryUsage: false,
            memoryUsageLabelName: 'memory',
            shouldExplicitlyCollectCommandExceptions: false,
            shouldUnwrapExceptions: false,
        );
        $interactor = new ElasticApmInteractor($config);

        $interactor->addContextFromConfig();

        // If no exception is thrown, the test passes
        $this->addToAssertionCount(1);
    }
}

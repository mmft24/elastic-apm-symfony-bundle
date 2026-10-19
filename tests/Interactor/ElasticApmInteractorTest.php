<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use Elastic\Apm\DistributedTracingData;
use Elastic\Apm\ElasticApm;
use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractor;
use ElasticApmBundle\Tests\Support\RealAgent;
use ElasticApmBundle\Tests\Support\RecordingEventSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real agent (not a mock) and asserts on the events it would ship to the APM server.
 *
 * Requires a usable agent: the elastic_apm extension loaded with ELASTIC_APM_ENABLED not set to false (the test
 * Docker image enables it with ELASTIC_APM_DISABLE_SEND=true, so nothing leaves the container).
 */
#[CoversClass(ElasticApmInteractor::class)]
final class ElasticApmInteractorTest extends TestCase
{
    private RecordingEventSink $sink;
    private ElasticApmInteractor $interactor;

    protected function setUp(): void
    {
        if (!RealAgent::isUsable()) {
            $this->markTestSkipped(RealAgent::skipReason());
        }

        $this->sink = RealAgent::record();
        $this->interactor = new ElasticApmInteractor($this->config());

        ElasticApm::beginCurrentTransaction('initial', 'request');
    }

    protected function tearDown(): void
    {
        if (RealAgent::isUsable()) {
            RealAgent::restore();
        }
    }

    public function testSetTransactionName(): void
    {
        $this->assertTrue($this->interactor->setTransactionName('renamed'));

        $this->assertSame('renamed', $this->endTransaction()['name']);
    }

    public function testAddLabelSupportsScalars(): void
    {
        $this->assertTrue($this->interactor->addLabel('string', 'value'));
        $this->assertTrue($this->interactor->addLabel('int', 42));
        $this->assertTrue($this->interactor->addLabel('float', 3.5));
        $this->assertTrue($this->interactor->addLabel('bool', true));
        $this->assertTrue($this->interactor->addLabel('null', null));

        $this->assertSame(
            ['string' => 'value', 'int' => 42, 'float' => 3.5, 'bool' => true, 'null' => null],
            $this->labels(),
        );
    }

    public function testAddLabelTruncatesLongKeysAndStringValuesTo1024Bytes(): void
    {
        $this->interactor->addLabel(\str_repeat('k', 2000), \str_repeat('v', 2000));

        $labels = $this->labels();
        $this->assertSame([\str_repeat('k', 1024) => \str_repeat('v', 1024)], $labels);
    }

    public function testAddLabelTruncationNeverSplitsAMultibyteCharacter(): void
    {
        // 'ä' is two bytes: 1024 bytes cannot end in the middle of one when truncated with mb_strcut().
        $this->interactor->addLabel('key', 'x'.\str_repeat('ä', 1000));

        $value = $this->labels()['key'];
        $this->assertIsString($value);
        $this->assertSame(1023, \strlen($value));
        $this->assertTrue(\mb_check_encoding($value, 'UTF-8'));
    }

    public function testAddCustomContextIsStoredAsLabel(): void
    {
        $this->assertTrue($this->interactor->addCustomContext('context-key', 'context-value'));

        $this->assertSame(['context-key' => 'context-value'], $this->labels());
    }

    public function testNoticeThrowableUnwrapsTheWholeChainWhenEnabled(): void
    {
        $root = new \LogicException('root');
        $middle = new \InvalidArgumentException('middle', 0, $root);
        $exception = new \RuntimeException('top', 0, $middle);

        $this->interactor->noticeThrowable($exception);
        $this->endTransaction();

        $this->assertSame(['top', 'middle', 'root'], $this->sink->errorMessages());
        $this->assertSame('RuntimeException', $this->sink->errors[0]['exception']['type']);
    }

    public function testNoticeThrowableReportsOnlyTheThrowableWithoutUnwrapping(): void
    {
        $interactor = new ElasticApmInteractor($this->config(unwrapExceptions: false));

        $interactor->noticeThrowable(new \RuntimeException('top', 0, new \LogicException('previous')));
        $this->endTransaction();

        $this->assertSame(['top'], $this->sink->errorMessages());
    }

    public function testNoticedErrorIsAttachedToTheCurrentTransaction(): void
    {
        $this->interactor->noticeThrowable(new \RuntimeException('boom'));
        $transaction = $this->endTransaction();

        $this->assertSame($transaction['id'], $this->sink->errors[0]['transaction_id']);
    }

    public function testGetCurrentTransactionReturnsTheAgentTransaction(): void
    {
        $this->assertSame(ElasticApm::getCurrentTransaction(), $this->interactor->getCurrentTransaction());
        $this->assertFalse($this->interactor->getCurrentTransaction()->isNoop());
    }

    public function testEndCurrentTransaction(): void
    {
        $this->assertTrue($this->interactor->endCurrentTransaction());

        $this->assertCount(1, $this->sink->transactions);
        $this->assertSame('initial', $this->sink->transactions[0]['name']);
        $this->assertTrue(ElasticApm::getCurrentTransaction()->isNoop());
    }

    public function testEndCurrentTransactionWithExplicitDuration(): void
    {
        $this->assertTrue($this->interactor->endCurrentTransaction(1.5));

        $this->assertEqualsWithDelta(1.5, $this->sink->transactions[0]['duration'], 0.001);
    }

    public function testBeginTransactionDoesNotReplaceTheCurrentOne(): void
    {
        $current = ElasticApm::getCurrentTransaction();
        $transaction = $this->interactor->beginTransaction('background', 'job');

        $this->assertSame($current, ElasticApm::getCurrentTransaction());

        $transaction->end();
        $this->assertSame('background', $this->sink->transactions[0]['name']);
        $this->assertSame('job', $this->sink->transactions[0]['type']);
        $this->assertSame('initial', $this->endTransaction()['name']);
    }

    public function testBeginCurrentTransactionBecomesCurrent(): void
    {
        $this->interactor->endCurrentTransaction();

        $transaction = $this->interactor->beginCurrentTransaction('worker', 'messaging', 1_700_000_000_000_000.0);

        $this->assertSame($transaction, ElasticApm::getCurrentTransaction());
        $this->assertSame('worker', $this->endTransaction()['name']);
        $this->assertEqualsWithDelta(1_700_000_000_000_000, $this->sink->transactions[1]['timestamp'], 1);
    }

    public function testBeginTransactionContinuesADistributedTrace(): void
    {
        $parent = new DistributedTracingData();
        $parent->traceId = '0af7651916cd43dd8448eb211c80319c';
        $parent->parentId = 'b7ad6b7169203331';
        $parent->isSampled = true;

        $transaction = $this->interactor->beginTransaction('child', 'request', null, $parent);
        $current = $this->interactor->beginCurrentTransaction('current-child', 'request', null, $parent);

        $this->assertSame($parent->traceId, $transaction->getTraceId());
        $this->assertSame($parent->parentId, $transaction->getParentId());
        $this->assertSame($parent->traceId, $current->getTraceId());
        $this->assertSame($parent->parentId, $current->getParentId());

        $current->end();
        $transaction->end();
    }

    public function testBeginCurrentTransactionFromHeadersContinuesTheTraceWithItsSampleRate(): void
    {
        $this->interactor->endCurrentTransaction();

        $transaction = $this->interactor->beginCurrentTransactionFromHeaders(
            'consumer',
            'message',
            // Header names are matched case-insensitively, like HTTP headers.
            ['TraceParent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', 'tracestate' => 'es=s:0.5'],
            1_700_000_000_000_000.0,
        );

        $this->assertSame($transaction, ElasticApm::getCurrentTransaction());
        $recorded = $this->endTransaction();
        $this->assertSame('consumer', $recorded['name']);
        $this->assertSame('message', $recorded['type']);
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $recorded['trace_id']);
        $this->assertSame('b7ad6b7169203331', $recorded['parent_id']);
        $this->assertEqualsWithDelta(0.5, $recorded['sample_rate'], 0.0001);
        $this->assertEqualsWithDelta(1_700_000_000_000_000, $recorded['timestamp'], 1);
    }

    public function testBeginCurrentTransactionFromHeadersStartsANewTraceWithoutUsableHeaders(): void
    {
        $this->interactor->endCurrentTransaction();

        $this->interactor->beginCurrentTransactionFromHeaders('consumer', 'message', ['traceparent' => '00-nonsense-01']);

        $recorded = $this->endTransaction();
        $this->assertSame('consumer', $recorded['name']);
        $this->assertArrayNotHasKey('parent_id', $recorded);
    }

    public function testBeginAndEndCurrentSpan(): void
    {
        $span = $this->interactor->beginCurrentSpan('SELECT', 'db', 'mysql', 'query');

        $this->assertSame($span, ElasticApm::getCurrentTransaction()->getCurrentSpan());
        $this->assertTrue($this->interactor->endCurrentSpan());

        $this->endTransaction();
        $this->assertCount(1, $this->sink->spans);
        $this->assertSame('SELECT', $this->sink->spans[0]['name']);
        $this->assertSame('db', $this->sink->spans[0]['type']);
        $this->assertSame('mysql', $this->sink->spans[0]['subtype']);
        $this->assertSame('query', $this->sink->spans[0]['action']);
    }

    public function testEndCurrentSpanWithExplicitDuration(): void
    {
        $this->interactor->beginCurrentSpan('span', 'custom');
        $this->interactor->endCurrentSpan(0.5);

        $this->endTransaction();
        $this->assertEqualsWithDelta(0.5, $this->sink->spans[0]['duration'], 0.001);
    }

    public function testCaptureCurrentSpanReturnsTheCallbackResultAndRecordsTheSpan(): void
    {
        $result = $this->interactor->captureCurrentSpan(
            'capture',
            'db',
            static fn(): int => 42,
            'mysql',
            'query',
        );

        $this->assertSame(42, $result);

        $this->endTransaction();
        $this->assertSame('capture', $this->sink->spans[0]['name']);
        $this->assertSame('mysql', $this->sink->spans[0]['subtype']);
    }

    public function testSetUserAttributesAddsOnlyNonNullValues(): void
    {
        $this->assertTrue($this->interactor->setUserAttributes('user-123', null, 'testuser'));

        $this->assertSame(['user_id' => 'user-123', 'user_username' => 'testuser'], $this->labels());
    }

    public function testSetUserAttributesWithAllValues(): void
    {
        $this->interactor->setUserAttributes('user-123', 'user@example.com', 'testuser');

        $this->assertSame(
            ['user_id' => 'user-123', 'user_email' => 'user@example.com', 'user_username' => 'testuser'],
            $this->labels(),
        );
    }

    public function testSetUserAttributesWithAllNullAddsNothing(): void
    {
        $this->assertTrue($this->interactor->setUserAttributes(null, null, null));

        $this->assertSame([], $this->labels());
    }

    public function testAddContextFromConfigAddsMemoryUsageLabelsAndContext(): void
    {
        $this->interactor->addContextFromConfig();

        $labels = $this->labels();
        $this->assertIsInt($labels['memory_peak'] ?? null);
        $this->assertGreaterThan(0, $labels['memory_peak']);
        $this->assertSame('value', $labels['custom_label'] ?? null);
        $this->assertSame('ctx_value', $labels['custom_context'] ?? null);
    }

    public function testAddContextFromConfigWithoutMemoryUsage(): void
    {
        $interactor = new ElasticApmInteractor($this->config(collectMemoryUsage: false));

        $interactor->addContextFromConfig();

        $this->assertSame(['custom_label' => 'value', 'custom_context' => 'ctx_value'], $this->labels());
    }

    public function testAddContextFromConfigSeesLabelsAddedToTheConfigAtRuntime(): void
    {
        $config = $this->config(collectMemoryUsage: false);
        $interactor = new ElasticApmInteractor($config);

        $config->addCustomLabels('tenant', 'acme');
        $interactor->addContextFromConfig();

        $this->assertSame('acme', $this->labels()['tenant'] ?? null);
    }

    private function config(bool $collectMemoryUsage = true, bool $unwrapExceptions = true): Config
    {
        return new Config(
            customLabels: ['custom_label' => 'value'],
            customContext: ['custom_context' => 'ctx_value'],
            shouldCollectMemoryUsage: $collectMemoryUsage,
            memoryUsageLabelName: 'memory_peak',
            shouldExplicitlyCollectCommandExceptions: false,
            shouldUnwrapExceptions: $unwrapExceptions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function endTransaction(): array
    {
        ElasticApm::getCurrentTransaction()->end();

        $transactions = $this->sink->transactions;
        $last = \end($transactions);
        $this->assertIsArray($last, 'No transaction was recorded');

        return $last;
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(): array
    {
        /** @var array<string, mixed> */
        return $this->endTransaction()['context']['tags'] ?? [];
    }
}

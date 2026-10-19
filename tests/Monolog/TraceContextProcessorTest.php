<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Monolog;

use Elastic\Apm\SpanInterface;
use Elastic\Apm\TransactionInterface;
use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Monolog\TraceContextProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContextProcessor::class)]
final class TraceContextProcessorTest extends TestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string TRANSACTION_ID = 'b7ad6b7169203331';

    private const string SPAN_ID = '00f067aa0ba902b7';

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__).'/Fixtures/ElasticApmAgentApi.php';
    }

    public function testAddsTraceAndTransactionIdsWhenThereIsATransaction(): void
    {
        $processor = new TraceContextProcessor($this->interactorReturning($this->transaction()));

        $record = $processor($this->record(extra: ['existing' => 'kept']));

        $this->assertSame(
            [
                'existing' => 'kept',
                'trace.id' => self::TRACE_ID,
                'transaction.id' => self::TRANSACTION_ID,
            ],
            $record->extra,
        );
    }

    public function testAddsSpanIdWhenThereIsACurrentSpan(): void
    {
        $processor = new TraceContextProcessor($this->interactorReturning($this->transaction($this->span())));

        $record = $processor($this->record());

        $this->assertSame(
            [
                'trace.id' => self::TRACE_ID,
                'transaction.id' => self::TRANSACTION_ID,
                'span.id' => self::SPAN_ID,
            ],
            $record->extra,
        );
    }

    public function testLeavesRecordUnchangedWithoutTransaction(): void
    {
        $processor = new TraceContextProcessor($this->interactorReturning(null));
        $record = $this->record(extra: ['existing' => 'kept']);

        $this->assertSame($record, $processor($record));
    }

    public function testLeavesRecordUnchangedWithBlackholeInteractor(): void
    {
        $processor = new TraceContextProcessor(new BlackholeInteractor());
        $record = $this->record();

        $this->assertSame($record, $processor($record));
    }

    public function testLeavesRecordUnchangedWhenTheAgentReturnsANoopTransaction(): void
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->method('isNoop')->willReturn(true);
        $transaction->expects($this->never())->method('getTraceId');
        $transaction->expects($this->never())->method('getId');

        $processor = new TraceContextProcessor($this->interactorReturning($transaction));
        $record = $this->record();

        $this->assertSame($record, $processor($record));
    }

    public function testLeavesRecordUnchangedWhenTheInteractorThrows(): void
    {
        $interactor = $this->createStub(ElasticApmInteractorInterface::class);
        $interactor->method('getCurrentTransaction')->willThrowException(new \RuntimeException('agent failure'));

        $processor = new TraceContextProcessor($interactor);
        $record = $this->record();

        $this->assertSame($record, $processor($record));
    }

    public function testLeavesRecordUnchangedWhenTheTransactionThrows(): void
    {
        $transaction = $this->createStub(TransactionInterface::class);
        $transaction->method('isNoop')->willReturn(false);
        $transaction->method('getTraceId')->willReturn(self::TRACE_ID);
        $transaction->method('getId')->willReturn(self::TRANSACTION_ID);
        $transaction->method('getCurrentSpan')->willThrowException(new \Error('agent failure'));

        $processor = new TraceContextProcessor($this->interactorReturning($transaction));
        $record = $this->record();

        // No partially-correlated record either: it is all or nothing.
        $this->assertSame($record, $processor($record));
    }

    public function testUsesConfiguredFieldNames(): void
    {
        $processor = new TraceContextProcessor(
            $this->interactorReturning($this->transaction($this->span())),
            'apm_trace',
            'apm_transaction',
            'apm_span',
        );

        $record = $processor($this->record());

        $this->assertSame(
            [
                'apm_trace' => self::TRACE_ID,
                'apm_transaction' => self::TRANSACTION_ID,
                'apm_span' => self::SPAN_ID,
            ],
            $record->extra,
        );
    }

    public function testDoesNotTouchContextOrMessage(): void
    {
        $processor = new TraceContextProcessor($this->interactorReturning($this->transaction()));
        $original = $this->record(context: ['user' => 42]);

        $record = $processor($original);

        $this->assertSame('Something happened', $record->message);
        $this->assertSame(['user' => 42], $record->context);
        $this->assertSame([], $original->extra, 'The original record must not be mutated.');
    }

    public function testCorrelatesRecordsLoggedThroughARealMonologLogger(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('app', [$handler], [
            new TraceContextProcessor($this->interactorReturning($this->transaction($this->span()))),
        ]);

        $logger->warning('Payment declined', ['order' => 'A-1']);

        $this->assertTrue($handler->hasWarningThatContains('Payment declined'));
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(['order' => 'A-1'], $records[0]->context);
        $this->assertSame(
            [
                'trace.id' => self::TRACE_ID,
                'transaction.id' => self::TRANSACTION_ID,
                'span.id' => self::SPAN_ID,
            ],
            $records[0]->extra,
        );
    }

    public function testRealMonologLoggerStillLogsWithoutTransaction(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('app', [$handler], [new TraceContextProcessor(new BlackholeInteractor())]);

        $logger->info('No APM here');

        $this->assertTrue($handler->hasInfoThatContains('No APM here'));
        $this->assertSame([], $handler->getRecords()[0]->extra);
    }

    private function interactorReturning(?TransactionInterface $transaction): ElasticApmInteractorInterface
    {
        $interactor = $this->createStub(ElasticApmInteractorInterface::class);
        $interactor->method('getCurrentTransaction')->willReturn($transaction);

        return $interactor;
    }

    private function transaction(?SpanInterface $currentSpan = null): TransactionInterface
    {
        $transaction = $this->createStub(TransactionInterface::class);
        $transaction->method('isNoop')->willReturn(false);
        $transaction->method('getTraceId')->willReturn(self::TRACE_ID);
        $transaction->method('getId')->willReturn(self::TRANSACTION_ID);
        // Like the agent, a transaction without an active span returns a no-op span rather than null.
        $transaction->method('getCurrentSpan')->willReturn($currentSpan ?? $this->noopSpan());

        return $transaction;
    }

    private function span(): SpanInterface
    {
        $span = $this->createStub(SpanInterface::class);
        $span->method('isNoop')->willReturn(false);
        $span->method('getId')->willReturn(self::SPAN_ID);

        return $span;
    }

    private function noopSpan(): SpanInterface
    {
        $span = $this->createStub(SpanInterface::class);
        $span->method('isNoop')->willReturn(true);
        $span->method('getId')->willReturn('0000000000000000');

        return $span;
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    private function record(array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Something happened',
            context: $context,
            extra: $extra,
        );
    }
}

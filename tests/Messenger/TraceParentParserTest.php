<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use Elastic\Apm\DistributedTracingData;
use ElasticApmBundle\Messenger\TraceParentParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Fixtures/elastic_apm_api.inc';

#[CoversClass(TraceParentParser::class)]
final class TraceParentParserTest extends TestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string PARENT_ID = 'b9c7c989f97918e1';

    public function testParsesSampledTraceParent(): void
    {
        $data = TraceParentParser::parse('00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-01');

        $this->assertInstanceOf(DistributedTracingData::class, $data);
        $this->assertSame(self::TRACE_ID, $data->traceId);
        $this->assertSame(self::PARENT_ID, $data->parentId);
        $this->assertTrue($data->isSampled);
    }

    public function testParsesUnsampledTraceParent(): void
    {
        $data = TraceParentParser::parse('00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-00');

        $this->assertInstanceOf(DistributedTracingData::class, $data);
        $this->assertFalse($data->isSampled);
    }

    public function testSampledIsReadFromTheFlagBitNotTheWholeFlagsValue(): void
    {
        $sampled = TraceParentParser::parse('00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-03');
        $notSampled = TraceParentParser::parse('00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-02');

        $this->assertNotNull($sampled);
        $this->assertNotNull($notSampled);
        $this->assertTrue($sampled->isSampled);
        $this->assertFalse($notSampled->isSampled);
    }

    public function testNormalisesIdsAndSurroundingWhitespace(): void
    {
        $data = TraceParentParser::parse(' 00-'.\strtoupper(self::TRACE_ID).'-'.\strtoupper(self::PARENT_ID)."-01\n");

        $this->assertNotNull($data);
        $this->assertSame(self::TRACE_ID, $data->traceId);
        $this->assertSame(self::PARENT_ID, $data->parentId);
    }

    public function testAcceptsTrailingFieldsFromFutureVersions(): void
    {
        $data = TraceParentParser::parse('cc-'.self::TRACE_ID.'-'.self::PARENT_ID.'-01-what-the-future-holds');

        $this->assertSame(self::TRACE_ID, $data?->traceId);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidTraceParents(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'garbage' => ['not a traceparent'];
        yield 'too few parts' => ['00-'.self::TRACE_ID.'-'.self::PARENT_ID];
        yield 'extra part on version 00' => ['00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-01-extra'];
        yield 'forbidden version ff' => ['ff-'.self::TRACE_ID.'-'.self::PARENT_ID.'-01'];
        yield 'non hex version' => ['zz-'.self::TRACE_ID.'-'.self::PARENT_ID.'-01'];
        yield 'short trace id' => ['00-0af7651916cd43dd-'.self::PARENT_ID.'-01'];
        yield 'non hex trace id' => ['00-'.\str_repeat('g', 32).'-'.self::PARENT_ID.'-01'];
        yield 'all-zero trace id' => ['00-'.\str_repeat('0', 32).'-'.self::PARENT_ID.'-01'];
        yield 'short parent id' => ['00-'.self::TRACE_ID.'-b9c7c989-01'];
        yield 'non hex parent id' => ['00-'.self::TRACE_ID.'-'.\str_repeat('x', 16).'-01'];
        yield 'all-zero parent id' => ['00-'.self::TRACE_ID.'-'.\str_repeat('0', 16).'-01'];
        yield 'one char flags' => ['00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-1'];
        yield 'non hex flags' => ['00-'.self::TRACE_ID.'-'.self::PARENT_ID.'-0z'];
    }

    #[DataProvider('invalidTraceParents')]
    public function testMalformedTraceParentIsIgnored(?string $traceParent): void
    {
        $this->assertNull(TraceParentParser::parse($traceParent));
    }
}

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use ElasticApmBundle\Messenger\ApmTracingStamp;
use ElasticApmBundle\Tests\Messenger\Fixtures\TestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

#[CoversClass(ApmTracingStamp::class)]
final class ApmTracingStampTest extends TestCase
{
    private const array HEADERS = [
        'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b9c7c989f97918e1-01',
        'tracestate' => 'es=s:1',
    ];

    public function testExposesHeaders(): void
    {
        $stamp = new ApmTracingStamp(self::HEADERS);

        $this->assertSame(self::HEADERS, $stamp->getHeaders());
        $this->assertSame(self::HEADERS['traceparent'], $stamp->getHeader('traceparent'));
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $stamp = new ApmTracingStamp(['TraceParent' => 'value']);

        $this->assertSame('value', $stamp->getHeader('traceparent'));
    }

    public function testMissingHeaderIsNull(): void
    {
        $this->assertNull((new ApmTracingStamp([]))->getHeader('traceparent'));
    }

    /**
     * @return iterable<string, array{SerializerInterface}>
     */
    public static function serializers(): iterable
    {
        yield 'php serializer' => [new PhpSerializer()];
        yield 'symfony serializer (json)' => [Serializer::create()];
    }

    #[DataProvider('serializers')]
    public function testStampSurvivesTransportSerializerRoundTrip(SerializerInterface $serializer): void
    {
        $envelope = new Envelope(new TestMessage(), [new ApmTracingStamp(self::HEADERS)]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        $stamp = $decoded->last(ApmTracingStamp::class);
        $this->assertInstanceOf(ApmTracingStamp::class, $stamp);
        $this->assertSame(self::HEADERS, $stamp->getHeaders());
    }
}

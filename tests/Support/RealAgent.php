<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Support;

use Elastic\Apm\Impl\GlobalTracerHolder;
use Elastic\Apm\Impl\TracerBuilder;
use Elastic\Apm\Impl\TracerInterface;

/**
 * Swaps the agent's global tracer for one that records into a {@see RecordingEventSink}, so tests drive the real
 * agent API (through ElasticApmInteractor) and assert on exactly what would be shipped to the APM server.
 *
 * Uses agent internals (`Elastic\Apm\Impl\*`); the agent version is pinned in the Dockerfile.
 */
final class RealAgent
{
    private static ?TracerInterface $previousTracer = null;

    public static function isUsable(): bool
    {
        // The extension alone is not enough: with ELASTIC_APM_ENABLED=false the agent's PHP part is never loaded.
        return \extension_loaded('elastic_apm') && \class_exists(\Elastic\Apm\ElasticApm::class);
    }

    public static function skipReason(): string
    {
        return \extension_loaded('elastic_apm')
            ? 'The elastic_apm extension is loaded but the agent is disabled (ELASTIC_APM_ENABLED=false)'
            : 'The elastic_apm extension is not loaded';
    }

    public static function record(): RecordingEventSink
    {
        self::$previousTracer = GlobalTracerHolder::isValueSet() ? GlobalTracerHolder::getValue() : null;

        $sink = new RecordingEventSink();
        GlobalTracerHolder::setValue(TracerBuilder::startNew()->withEventSink($sink)->build());

        return $sink;
    }

    public static function restore(): void
    {
        if (null === self::$previousTracer) {
            GlobalTracerHolder::unsetValue();

            return;
        }

        GlobalTracerHolder::setValue(self::$previousTracer);
        self::$previousTracer = null;
    }
}

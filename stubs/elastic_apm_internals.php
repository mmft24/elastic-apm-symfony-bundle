<?php

// Agent internals used by the test suite's RealAgent/RecordingEventSink helpers (tests/Support). Scanned by PHPStan
// only, so the tests analyse without the agent installed; the real classes ship with apm-agent-php 1.15.

namespace Elastic\Apm\Impl;

interface TracerInterface {}

final class GlobalTracerHolder
{
    public static function isValueSet(): bool {}

    public static function getValue(): TracerInterface {}

    public static function setValue(TracerInterface $newInstance): void {}

    public static function unsetValue(): void {}
}

final class TracerBuilder
{
    public static function startNew(bool $shouldCheckExtension = true): self {}

    public function withEventSink(EventSinkInterface $eventSink): self {}

    public function build(): TracerInterface {}
}

interface EventSinkInterface
{
    /**
     * @param list<object> $spans
     * @param list<object> $errors
     */
    public function consume(
        Metadata $metadata,
        array $spans,
        array $errors,
        ?BreakdownMetrics\PerTransaction $breakdownMetricsPerTransaction,
        ?Transaction $transaction,
    ): void;
}

class Metadata {}

class Transaction {}

namespace Elastic\Apm\Impl\BreakdownMetrics;

class PerTransaction {}

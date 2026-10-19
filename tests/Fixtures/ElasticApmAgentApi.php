<?php

declare(strict_types=1);

namespace Elastic\Apm;

// Copies of the agent API interfaces from stubs/elastic_apm.php so tests can mock transactions and spans: the
// test image ships the elastic_apm extension disabled, so the agent's own PHP API is not loaded. Only interfaces
// are declared (never the ElasticApm facade) to keep AdaptiveInteractor's extension detection unaffected.

if (!\interface_exists(ExecutionSegmentInterface::class)) {
    interface ExecutionSegmentInterface
    {
        public function getId(): string;

        public function getTraceId(): string;

        public function getTimestamp(): float;

        public function beginChildSpan(
            string $name,
            string $type,
            ?string $subtype = null,
            ?string $action = null,
            ?float $timestamp = null
        ): SpanInterface;

        public function captureChildSpan(
            string $name,
            string $type,
            \Closure $callback,
            ?string $subtype = null,
            ?string $action = null,
            ?float $timestamp = null
        ): mixed;

        public function setName(string $name): void;

        public function setType(string $type): void;

        public function getDistributedTracingData(): ?DistributedTracingData;

        public function injectDistributedTracingHeaders(\Closure $headerInjector): void;

        public function end(?float $duration = null): void;

        public function hasEnded(): bool;

        public function createErrorFromThrowable(\Throwable $throwable): ?string;

        public function createCustomError(CustomErrorData $customErrorData): ?string;

        public function setOutcome(?string $outcome): void;

        public function getOutcome(): ?string;

        public function isNoop(): bool;

        public function discard(): void;
    }
}

if (!\interface_exists(TransactionInterface::class)) {
    interface TransactionInterface extends ExecutionSegmentInterface
    {
        public function isSampled(): bool;

        public function getParentId(): ?string;

        public function beginCurrentSpan(
            string $name,
            string $type,
            ?string $subtype = null,
            ?string $action = null,
            ?float $timestamp = null
        ): SpanInterface;

        public function captureCurrentSpan(
            string $name,
            string $type,
            \Closure $callback,
            ?string $subtype = null,
            ?string $action = null,
            ?float $timestamp = null
        ): mixed;

        public function getCurrentSpan(): SpanInterface;

        public function context(): TransactionContextInterface;

        public function setResult(?string $result): void;

        public function getResult(): ?string;

        public function ensureParentId(): string;
    }
}

if (!\interface_exists(SpanInterface::class)) {
    interface SpanInterface extends ExecutionSegmentInterface
    {
        public function getTransactionId(): string;

        public function getParentId(): string;

        public function setAction(?string $action): void;

        public function setSubtype(?string $subtype): void;

        public function context(): SpanContextInterface;

        public function endSpanEx(int $numberOfStackFramesToSkip, ?float $duration = null): void;
    }
}

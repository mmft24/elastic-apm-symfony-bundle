<?php

declare(strict_types=1);

// The agent's PHP API is only loaded when the elastic_apm extension is enabled. These minimal stand-ins (only the
// members the Messenger integration uses) let the tests mock transactions and build tracing data without it.
// The .inc extension keeps static analysis on the full agent API instead of these partial declarations.

namespace Elastic\Apm;

if (!\interface_exists(TransactionInterface::class)) {
    interface TransactionInterface
    {
        public function getTraceId(): string;

        public function setResult(?string $result): void;

        public function setOutcome(?string $outcome): void;

        public function injectDistributedTracingHeaders(\Closure $headerInjector): void;

        public function end(?float $duration = null): void;

        public function hasEnded(): bool;

        public function isNoop(): bool;

        public function discard(): void;
    }
}

if (!\class_exists(DistributedTracingData::class)) {
    class DistributedTracingData
    {
        /** @var string */
        public $traceId;

        /** @var string */
        public $parentId;

        /** @var bool */
        public $isSampled;
    }
}

<?php

declare(strict_types=1);

// The agent's PHP API is only loaded when the elastic_apm extension is enabled. This stand-in lets the tests build
// tracing data without it. The .inc extension keeps static analysis on the full agent API.

namespace Elastic\Apm;

require_once \dirname(__DIR__, 2).'/Fixtures/ElasticApmAgentApi.php';

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

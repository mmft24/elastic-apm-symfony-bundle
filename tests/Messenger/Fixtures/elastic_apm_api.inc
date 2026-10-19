<?php

declare(strict_types=1);

// The agent's PHP API is only loaded when the elastic_apm extension is enabled; the shared stand-ins let the tests
// mock transactions and spans without it.

require_once \dirname(__DIR__, 2).'/Fixtures/ElasticApmAgentApi.php';

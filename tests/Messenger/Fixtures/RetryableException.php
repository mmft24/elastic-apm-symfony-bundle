<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger\Fixtures;

final class RetryableException extends \RuntimeException implements ExpectedRetryExceptionInterface {}

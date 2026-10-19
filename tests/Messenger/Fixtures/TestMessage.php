<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger\Fixtures;

final class TestMessage
{
    public function __construct(
        public readonly string $payload = 'payload',
    ) {}
}

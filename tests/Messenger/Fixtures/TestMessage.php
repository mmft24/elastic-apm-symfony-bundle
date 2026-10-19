<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger\Fixtures;

final readonly class TestMessage
{
    public function __construct(
        public string $payload = 'payload',
    ) {}
}

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration\Fixtures;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param string|\Stringable $message untyped to stay compatible with psr/log 1.x
     * @param array<mixed>        $context
     */
    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return \array_column($this->records, 1);
    }
}

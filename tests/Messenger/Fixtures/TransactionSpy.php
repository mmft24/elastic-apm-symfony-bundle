<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger\Fixtures;

use Elastic\Apm\TransactionInterface;

final class TransactionSpy
{
    public ?string $result = null;
    public ?string $outcome = null;
    public bool $discarded = false;
    public int $endCalls = 0;

    public function __construct(
        public readonly TransactionInterface $transaction,
    ) {}

    public function hasEnded(): bool
    {
        return $this->endCalls > 0;
    }
}

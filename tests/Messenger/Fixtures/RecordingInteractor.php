<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger\Fixtures;

use Elastic\Apm\DistributedTracingData;
use Elastic\Apm\SpanInterface;
use Elastic\Apm\TransactionInterface;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;

require_once __DIR__.'/elastic_apm_api.inc';

/**
 * Behaves like the agent for the current transaction (begin makes it current, end clears it) and records the calls.
 */
final class RecordingInteractor implements ElasticApmInteractorInterface
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];

    /** @var list<array{name: string, type: string, tracingData: ?DistributedTracingData}> */
    public array $begun = [];

    /** @var list<\Throwable> */
    public array $noticed = [];

    /**
     * @param \Closure(): ?TransactionInterface $transactionFactory
     */
    public function __construct(
        private readonly \Closure $transactionFactory,
        public ?TransactionInterface $current = null,
    ) {}

    /**
     * @return list<string>
     */
    public function callNames(): array
    {
        return \array_map(static fn(array $call): string => $call[0], $this->calls);
    }

    #[\Override]
    public function setTransactionName(string $name): bool
    {
        $this->calls[] = ['setTransactionName', [$name]];

        return true;
    }

    #[\Override]
    public function addLabel(string $name, $value): bool
    {
        $this->calls[] = ['addLabel', [$name, $value]];

        return true;
    }

    #[\Override]
    public function addCustomContext(string $name, $value): bool
    {
        $this->calls[] = ['addCustomContext', [$name, $value]];

        return true;
    }

    #[\Override]
    public function noticeThrowable(\Throwable $e): void
    {
        $this->calls[] = ['noticeThrowable', [$e]];
        $this->noticed[] = $e;
    }

    #[\Override]
    public function beginTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null,
    ): ?TransactionInterface {
        $this->calls[] = ['beginTransaction', [$name, $type, $timestamp, $distributedTracingData]];

        return ($this->transactionFactory)();
    }

    #[\Override]
    public function beginCurrentTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null,
    ): ?TransactionInterface {
        $this->calls[] = ['beginCurrentTransaction', [$name, $type, $timestamp, $distributedTracingData]];
        $this->begun[] = ['name' => $name, 'type' => $type, 'tracingData' => $distributedTracingData];

        $this->current = ($this->transactionFactory)();

        return $this->current;
    }

    #[\Override]
    public function endCurrentTransaction(?float $duration = null): bool
    {
        $this->calls[] = ['endCurrentTransaction', [$duration]];
        $this->current?->end($duration);
        $this->current = null;

        return true;
    }

    #[\Override]
    public function getCurrentTransaction(): ?TransactionInterface
    {
        return $this->current;
    }

    #[\Override]
    public function beginCurrentSpan(
        string $name,
        string $type,
        ?string $subtype = null,
        ?string $action = null,
        ?float $timestamp = null,
    ): ?SpanInterface {
        $this->calls[] = ['beginCurrentSpan', [$name, $type]];

        return null;
    }

    #[\Override]
    public function endCurrentSpan(?float $duration = null): bool
    {
        $this->calls[] = ['endCurrentSpan', [$duration]];

        return true;
    }

    #[\Override]
    public function captureCurrentSpan(
        string $name,
        string $type,
        \Closure $callback,
        ?string $subtype = null,
        ?string $action = null,
        ?float $timestamp = null,
    ): mixed {
        $this->calls[] = ['captureCurrentSpan', [$name, $type]];

        return $callback(null);
    }

    #[\Override]
    public function setUserAttributes(?string $id, ?string $email, ?string $username): bool
    {
        $this->calls[] = ['setUserAttributes', [$id, $email, $username]];

        return true;
    }

    #[\Override]
    public function addContextFromConfig(): void
    {
        $this->calls[] = ['addContextFromConfig', []];
    }
}

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration\Fixtures;

use Elastic\Apm\DistributedTracingData;
use Elastic\Apm\SpanInterface;
use Elastic\Apm\TransactionInterface;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;

/**
 * Records every call the bundle makes, so integration tests can assert the exact interactor traffic.
 */
final class RecordingInteractor implements ElasticApmInteractorInterface
{
    /** @var list<array{string, list<mixed>}> */
    private array $calls = [];

    /**
     * @return list<array{string, list<mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return \array_column($this->calls, 0);
    }

    /**
     * @return list<list<mixed>> the arguments of every call to $method, in order
     */
    public function callsTo(string $method): array
    {
        return \array_values(\array_map(
            static fn(array $call): array => $call[1],
            \array_filter($this->calls, static fn(array $call): bool => $call[0] === $method),
        ));
    }

    /**
     * @return array<string, mixed> custom context name => value, last write wins
     */
    public function customContext(): array
    {
        $context = [];
        foreach ($this->callsTo('addCustomContext') as [$name, $value]) {
            $context[(string) $name] = $value;
        }

        return $context;
    }

    #[\Override]
    public function setTransactionName(string $name): bool
    {
        return $this->record(__FUNCTION__, $name);
    }

    #[\Override]
    public function addLabel(string $name, $value): bool
    {
        return $this->record(__FUNCTION__, $name, $value);
    }

    #[\Override]
    public function addCustomContext(string $name, $value): bool
    {
        return $this->record(__FUNCTION__, $name, $value);
    }

    #[\Override]
    public function noticeThrowable(\Throwable $e): void
    {
        $this->record(__FUNCTION__, $e);
    }

    #[\Override]
    public function beginTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null,
    ): ?TransactionInterface {
        $this->record(__FUNCTION__, $name, $type, $timestamp, $distributedTracingData);

        return null;
    }

    #[\Override]
    public function beginCurrentTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null,
    ): ?TransactionInterface {
        $this->record(__FUNCTION__, $name, $type, $timestamp, $distributedTracingData);

        return null;
    }

    #[\Override]
    public function beginCurrentTransactionFromHeaders(
        string $name,
        string $type,
        array $headers,
        ?float $timestamp = null,
    ): ?TransactionInterface {
        $this->record(__FUNCTION__, $name, $type, $headers, $timestamp);

        return null;
    }

    #[\Override]
    public function endCurrentTransaction(?float $duration = null): bool
    {
        return $this->record(__FUNCTION__, $duration);
    }

    #[\Override]
    public function getCurrentTransaction(): ?TransactionInterface
    {
        $this->record(__FUNCTION__);

        return null;
    }

    #[\Override]
    public function beginCurrentSpan(
        string $name,
        string $type,
        ?string $subtype = null,
        ?string $action = null,
        ?float $timestamp = null,
    ): ?SpanInterface {
        $this->record(__FUNCTION__, $name, $type, $subtype, $action, $timestamp);

        return null;
    }

    #[\Override]
    public function endCurrentSpan(?float $duration = null): bool
    {
        return $this->record(__FUNCTION__, $duration);
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
        $this->record(__FUNCTION__, $name, $type, $subtype, $action, $timestamp);

        return $callback(null);
    }

    #[\Override]
    public function setUserAttributes(?string $id, ?string $email, ?string $username): bool
    {
        return $this->record(__FUNCTION__, $id, $email, $username);
    }

    #[\Override]
    public function addContextFromConfig(): void
    {
        $this->record(__FUNCTION__);
    }

    private function record(string $method, mixed ...$arguments): bool
    {
        $this->calls[] = [$method, \array_values($arguments)];

        return true;
    }
}

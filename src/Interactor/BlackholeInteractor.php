<?php

declare(strict_types=1);

/*
 * This file is part of Ekino New Relic bundle.
 *
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\Interactor;

use Elastic\Apm\DistributedTracingData;
use Elastic\Apm\SpanInterface;
use Elastic\Apm\TransactionInterface;

/**
 * This interactor throw away any call.
 *
 * It can be used to avoid conditional log calls.
 */
final class BlackholeInteractor implements ElasticApmInteractorInterface
{
    #[\Override]
    public function setTransactionName(string $name): bool
    {
        return true;
    }

    #[\Override]
    public function addLabel(string $name, $value): bool
    {
        return true;
    }

    #[\Override]
    public function addCustomContext(string $name, $value): bool
    {
        return true;
    }

    #[\Override]
    public function noticeThrowable(\Throwable $e): void {}

    #[\Override]
    public function beginTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null,
    ): ?TransactionInterface {
        return null;
    }

    #[\Override]
    public function beginCurrentTransaction(
        string $name,
        string $type,
        ?float $timestamp = null,
        ?DistributedTracingData $distributedTracingData = null,
    ): ?TransactionInterface {
        return null;
    }

    #[\Override]
    public function endCurrentTransaction(?float $duration = null): bool
    {
        return true;
    }

    #[\Override]
    public function getCurrentTransaction(): ?TransactionInterface
    {
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
        return null;
    }

    #[\Override]
    public function endCurrentSpan(?float $duration = null): bool
    {
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
        return $callback(null);
    }

    #[\Override]
    public function setUserAttributes(?string $id, ?string $email, ?string $username): bool
    {
        return true;
    }

    #[\Override]
    public function addContextFromConfig(): void {}
}

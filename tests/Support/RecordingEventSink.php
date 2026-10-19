<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Support;

use Elastic\Apm\Impl\BreakdownMetrics\PerTransaction;
use Elastic\Apm\Impl\EventSinkInterface;
use Elastic\Apm\Impl\Metadata;
use Elastic\Apm\Impl\Transaction;

/**
 * Captures what the real agent would send to the APM server, decoded to plain arrays in the intake format.
 *
 * Only loadable when the agent's PHP part is bootstrapped (extension loaded and ELASTIC_APM_ENABLED not false).
 */
final class RecordingEventSink implements EventSinkInterface
{
    /** @var list<array<string, mixed>> */
    public array $transactions = [];

    /** @var list<array<string, mixed>> */
    public array $spans = [];

    /** @var list<array<string, mixed>> */
    public array $errors = [];

    #[\Override]
    public function consume(
        Metadata $metadata,
        array $spans,
        array $errors,
        ?PerTransaction $breakdownMetricsPerTransaction,
        ?Transaction $transaction,
    ): void {
        foreach ($spans as $span) {
            $this->spans[] = self::decode($span);
        }

        foreach ($errors as $error) {
            $this->errors[] = self::decode($error);
        }

        if (null !== $transaction) {
            $this->transactions[] = self::decode($transaction);
        }
    }

    /**
     * @return list<string>
     */
    public function errorMessages(): array
    {
        return \array_map(
            static fn(array $error): string => (string) ($error['exception']['message'] ?? ''),
            $this->errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(object $event): array
    {
        /** @var array<string, mixed> */
        return \json_decode(\json_encode($event, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
    }
}

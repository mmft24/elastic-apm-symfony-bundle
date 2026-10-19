<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger\Fixtures;

use Elastic\Apm\TransactionInterface;

require_once __DIR__.'/elastic_apm_api.inc';

trait CreatesTransactionSpies
{
    private const string PRODUCER_TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string PRODUCER_PARENT_ID = 'b9c7c989f97918e1';

    private const string PRODUCER_TRACEPARENT = '00-'.self::PRODUCER_TRACE_ID.'-'.self::PRODUCER_PARENT_ID.'-01';

    /**
     * @param array<string, string> $headers what injectDistributedTracingHeaders() hands to the injector
     */
    private function createTransactionSpy(
        array $headers = ['traceparent' => self::PRODUCER_TRACEPARENT],
        bool $noop = false,
    ): TransactionSpy {
        $transaction = $this->createStub(TransactionInterface::class);
        $spy = new TransactionSpy($transaction);

        $transaction->method('setResult')->willReturnCallback(static function (?string $result) use ($spy): void {
            $spy->result = $result;
        });
        $transaction->method('setOutcome')->willReturnCallback(static function (?string $outcome) use ($spy): void {
            $spy->outcome = $outcome;
        });
        $transaction->method('end')->willReturnCallback(static function () use ($spy): void {
            ++$spy->endCalls;
        });
        $transaction->method('discard')->willReturnCallback(static function () use ($spy): void {
            $spy->discarded = true;
            ++$spy->endCalls;
        });
        $transaction->method('hasEnded')->willReturnCallback(static fn(): bool => $spy->hasEnded());
        $transaction->method('isNoop')->willReturn($noop);
        $transaction->method('injectDistributedTracingHeaders')->willReturnCallback(
            static function (\Closure $injector) use ($headers): void {
                foreach ($headers as $name => $value) {
                    $injector($name, $value);
                }
            },
        );

        return $spy;
    }
}

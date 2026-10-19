<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use ElasticApmBundle\Interactor\AdaptiveInteractor;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The adaptive interactor picks the real interactor when the agent is usable (extension loaded AND its PHP part
 * bootstrapped, i.e. ELASTIC_APM_ENABLED is not false) and the fake one otherwise. Every test asserts against the
 * interactor the current environment selects, and that the other one is never touched, so the suite runs (and
 * means something) both with and without a working agent.
 */
#[CoversClass(AdaptiveInteractor::class)]
final class AdaptiveInteractorTest extends TestCase
{
    private static function agentIsUsable(): bool
    {
        return \extension_loaded('elastic_apm') && \class_exists(\Elastic\Apm\ElasticApm::class);
    }

    /**
     * @return iterable<string, array{non-empty-string, list<mixed>, mixed}>
     */
    public static function delegatedCalls(): iterable
    {
        $callback = static fn(): string => 'result';

        yield 'setTransactionName' => ['setTransactionName', ['transaction'], true];
        yield 'addLabel' => ['addLabel', ['key', 'value'], true];
        yield 'addCustomContext' => ['addCustomContext', ['key', 'value'], true];
        yield 'noticeThrowable' => ['noticeThrowable', [new \RuntimeException('Test')], null];
        yield 'beginTransaction' => ['beginTransaction', ['name', 'type', 1.5, null], null];
        yield 'beginCurrentTransaction' => ['beginCurrentTransaction', ['name', 'type', 1.5, null], null];
        yield 'beginCurrentTransactionFromHeaders' => [
            'beginCurrentTransactionFromHeaders',
            ['name', 'type', ['traceparent' => 'value'], 1.5],
            null,
        ];
        yield 'endCurrentTransaction' => ['endCurrentTransaction', [2.0], true];
        yield 'getCurrentTransaction' => ['getCurrentTransaction', [], null];
        yield 'beginCurrentSpan' => ['beginCurrentSpan', ['span', 'type', 'sub', 'act', 1.5], null];
        yield 'endCurrentSpan' => ['endCurrentSpan', [0.5], true];
        yield 'captureCurrentSpan' => ['captureCurrentSpan', ['name', 'type', $callback, 'sub', 'act', 1.5], 'result'];
        yield 'setUserAttributes' => ['setUserAttributes', ['id', 'email@test.com', 'username'], true];
        yield 'addContextFromConfig' => ['addContextFromConfig', [], null];
    }

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $arguments
     */
    #[DataProvider('delegatedCalls')]
    public function testDelegatesToTheInteractorSelectedForThisEnvironment(
        string $method,
        array $arguments,
        mixed $return,
    ): void {
        $selected = $this->createMock(ElasticApmInteractorInterface::class);
        $other = $this->createMock(ElasticApmInteractorInterface::class);

        $expectation = $selected->expects($this->once())
            ->method($method)
            ->with(...$arguments);

        if (null !== $return) {
            $expectation->willReturn($return);
        }

        $other->expects($this->never())->method($this->anything());

        [$real, $fake] = self::agentIsUsable() ? [$selected, $other] : [$other, $selected];

        $result = (new AdaptiveInteractor($real, $fake))->{$method}(...$arguments);

        $this->assertSame($return, $result);
    }
}

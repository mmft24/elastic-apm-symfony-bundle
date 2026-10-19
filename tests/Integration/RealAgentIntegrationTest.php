<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration;

use Elastic\Apm\ElasticApm;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Interactor\LoggingInteractorDecorator;
use ElasticApmBundle\Tests\Support\RealAgent;
use ElasticApmBundle\Tests\Support\RecordingEventSink;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * End to end with the default (adaptive) interactor and the real agent: asserts on what would reach the APM server.
 *
 * Requires a usable agent (see {@see RealAgent}).
 */
#[CoversNothing]
final class RealAgentIntegrationTest extends BundleKernelTestCase
{
    private RecordingEventSink $sink;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!RealAgent::isUsable()) {
            $this->markTestSkipped(RealAgent::skipReason());
        }

        $this->sink = RealAgent::record();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        if (RealAgent::isUsable()) {
            RealAgent::restore();
        }
    }

    public function testRequestTransactionIsNamedAndCarriesConfiguredContext(): void
    {
        self::bootWithConfig(self::agentConfig([
            'custom_labels' => ['team' => 'payments'],
            'custom_context' => ['region' => 'eu'],
            'track_memory_usage' => true,
        ]));

        ElasticApm::beginCurrentTransaction('GET unnamed', 'request');
        self::request('/hello/world');
        $transaction = $this->endTransaction();

        $this->assertSame('hello', $transaction['name']);
        $this->assertSame('payments', $transaction['context']['tags']['team']);
        $this->assertSame('eu', $transaction['context']['tags']['region']);
        $this->assertIsInt($transaction['context']['tags']['memory_usage']);
        $this->assertSame([], $this->sink->errors);
    }

    public function testControllerExceptionReachesTheAgentOnceWithItsCauses(): void
    {
        self::bootWithConfig(self::agentConfig(['exceptions' => ['unwrap_exceptions' => true]]));

        ElasticApm::beginCurrentTransaction('GET unnamed', 'request');
        self::request('/fail');
        $transaction = $this->endTransaction();

        $this->assertSame('fail', $transaction['name']);
        $this->assertSame(['Controller failed', 'Root cause'], $this->sink->errorMessages());
    }

    public function testSuccessfulCommandSetsResultOutcomeAndRedactsSecrets(): void
    {
        self::bootWithConfig(self::agentConfig());

        ElasticApm::beginCurrentTransaction('bin/console', 'cli');
        self::executeCommand(['command' => 'test:ok', '--password' => 's3cr3t']);
        $transaction = $this->endTransaction();

        $this->assertSame('test:ok', $transaction['name']);
        $this->assertSame('CLI 0', $transaction['result']);
        $this->assertSame('success', $transaction['outcome']);
        $this->assertSame('[REDACTED]', $transaction['context']['tags']['--password']);
    }

    public function testNonZeroExitCodeSetsFailureOutcome(): void
    {
        self::bootWithConfig(self::agentConfig());

        ElasticApm::beginCurrentTransaction('bin/console', 'cli');
        self::executeCommand(['command' => 'test:ok', '--exit-code' => '3']);
        $transaction = $this->endTransaction();

        $this->assertSame('CLI 3', $transaction['result']);
        $this->assertSame('failure', $transaction['outcome']);
        $this->assertSame([], $this->sink->errors);
    }

    public function testFailingCommandIsReportedOnceWhenExplicitlyCollected(): void
    {
        self::bootWithConfig(self::agentConfig([
            'commands' => ['explicitly_collect_exceptions' => true],
            'exceptions' => ['unwrap_exceptions' => true],
        ]));

        ElasticApm::beginCurrentTransaction('bin/console', 'cli');
        self::executeCommand(['command' => 'test:fail']);
        $transaction = $this->endTransaction();

        $this->assertSame('CLI 1', $transaction['result']);
        $this->assertSame('failure', $transaction['outcome']);
        $this->assertSame(['Command failed', 'Command root cause'], $this->sink->errorMessages());
    }

    public function testFailingCommandIsNotReportedByTheBundleWhenNotExplicitlyCollected(): void
    {
        self::bootWithConfig(self::agentConfig(['commands' => ['explicitly_collect_exceptions' => false]]));

        ElasticApm::beginCurrentTransaction('bin/console', 'cli');
        self::executeCommand(['command' => 'test:fail']);
        $transaction = $this->endTransaction();

        $this->assertSame('failure', $transaction['outcome']);
        $this->assertSame([], $this->sink->errors);
    }

    public function testLoggingDecoratorWrapsTheRealInteractor(): void
    {
        self::bootWithConfig(self::agentConfig(['logging' => true]));

        ElasticApm::beginCurrentTransaction('GET unnamed', 'request');
        self::request('/hello/world');

        $this->assertInstanceOf(
            LoggingInteractorDecorator::class,
            self::getContainer()->get(ElasticApmInteractorInterface::class),
        );
        $this->assertSame('hello', $this->endTransaction()['name']);
        $this->assertContains('Setting Elastic APM transaction name to {name}', self::logger()->messages());
    }

    /**
     * Default interactor (adaptive, which picks the real one here) with the error-handler listeners off.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function agentConfig(array $overrides = []): array
    {
        return \array_replace(['deprecations' => false, 'warnings' => false], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function endTransaction(): array
    {
        ElasticApm::getCurrentTransaction()->end();

        $transactions = $this->sink->transactions;
        $last = \end($transactions);
        $this->assertIsArray($last, 'No transaction was recorded');

        return $last;
    }
}

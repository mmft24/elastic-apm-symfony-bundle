<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Runs real console commands through the FrameworkBundle Application and asserts the exact interactor calls.
 */
#[CoversNothing]
final class ConsoleIntegrationTest extends BundleKernelTestCase
{
    public function testCommandNamesTheTransactionAndRecordsRedactedParameters(): void
    {
        self::bootWithConfig(self::recordingConfig([
            'commands' => ['sensitive_parameter_names' => ['target']],
        ]));

        $exitCode = self::executeCommand([
            'command' => 'test:ok',
            'target' => 'production-db',
            '--password' => 's3cr3t',
            '--tag' => ['a', 'b'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([['test:ok']], self::recorder()->callsTo('setTransactionName'));

        $context = self::recorder()->customContext();
        $this->assertSame('test:ok', $context['command']);
        $this->assertSame('[REDACTED]', $context['target']);
        $this->assertSame('[REDACTED]', $context['--password']);
        $this->assertSame('a', $context['--tag[0]']);
        $this->assertSame('b', $context['--tag[1]']);
        $this->assertNotContains('s3cr3t', $context);
        $this->assertNotContains('production-db', $context);

        $this->assertSame(['addContextFromConfig'], \array_values(\array_intersect(
            self::recorder()->methods(),
            ['addContextFromConfig', 'noticeThrowable'],
        )));
        // console.terminate looks up the transaction to set its result; the recorder has none, which is a no-op.
        $methods = self::recorder()->methods();
        $this->assertSame('getCurrentTransaction', \end($methods));
    }

    public function testFailingCommandIsReportedExactlyOnceWhenExplicitlyCollected(): void
    {
        self::bootWithConfig(self::recordingConfig([
            'commands' => ['explicitly_collect_exceptions' => true],
            'exceptions' => ['unwrap_exceptions' => true],
        ]));

        $exitCode = self::executeCommand(['command' => 'test:fail']);

        $this->assertSame(1, $exitCode);

        // Only the thrown exception reaches the interactor: unwrapping its causes is the interactor's job, so the
        // listener must not report the cause a second time.
        $noticed = self::recorder()->callsTo('noticeThrowable');
        $this->assertCount(1, $noticed);
        $this->assertInstanceOf(\RuntimeException::class, $noticed[0][0]);
        $this->assertSame('Command failed', $noticed[0][0]->getMessage());
    }

    public function testFailingCommandIsNotReportedWhenNotExplicitlyCollected(): void
    {
        self::bootWithConfig(self::recordingConfig([
            'commands' => ['explicitly_collect_exceptions' => false],
        ]));

        $exitCode = self::executeCommand(['command' => 'test:fail']);

        $this->assertSame(1, $exitCode);
        $this->assertSame([], self::recorder()->callsTo('noticeThrowable'));
        $this->assertSame([['test:fail']], self::recorder()->callsTo('setTransactionName'));
    }

    public function testHttpExceptionListenerDoesNotDoubleReportConsoleErrors(): void
    {
        // Both listeners enabled: only the command listener may report a console error.
        self::bootWithConfig(self::recordingConfig(['exceptions' => true, 'http' => true]));

        self::executeCommand(['command' => 'test:fail']);

        $this->assertCount(1, self::recorder()->callsTo('noticeThrowable'));
    }

    public function testCommandsDisabledLeavesTheTransactionAlone(): void
    {
        self::bootWithConfig(self::recordingConfig(['commands' => false]));

        $this->assertSame(0, self::executeCommand(['command' => 'test:ok']));
        $this->assertSame(1, self::executeCommand(['command' => 'test:fail']));

        $this->assertSame([], self::recorder()->calls());
    }

    public function testDisabledBundleRecordsNothing(): void
    {
        self::bootWithConfig(self::recordingConfig(['enabled' => false]));

        self::executeCommand(['command' => 'test:fail']);

        $this->assertSame([], self::recorder()->calls());
    }
}

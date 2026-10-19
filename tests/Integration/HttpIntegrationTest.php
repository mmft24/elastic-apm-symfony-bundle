<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration;

use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Interactor\ElasticApmInteractor;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Tests\Integration\Fixtures\IgnoredException;
use ElasticApmBundle\Tests\Integration\Fixtures\StaticNamingStrategy;
use ElasticApmBundle\Tests\Integration\Fixtures\TestController;
use ElasticApmBundle\Tests\Support\RealAgent;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Handles real requests through HttpKernel and asserts the exact interactor calls the bundle makes.
 */
final class HttpIntegrationTest extends BundleKernelTestCase
{
    public function testDefaultsNameTheTransactionAfterTheRouteAndAddConfiguredContext(): void
    {
        self::bootWithConfig(self::recordingConfig());

        $response = self::request('/hello/world');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [
                ['setTransactionName', ['hello']],
                ['addContextFromConfig', []],
            ],
            self::recorder()->calls(),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function namingStrategies(): iterable
    {
        yield 'route' => [['transaction_naming' => 'route'], '/hello/world', 'hello'];
        yield 'controller' => [
            ['transaction_naming' => 'controller'],
            '/hello/world',
            TestController::class.'::hello',
        ];
        yield 'controller as [class, method] array' => [
            ['transaction_naming' => 'controller'],
            '/hello-array/world',
            'Callback controller: '.TestController::class.'::hello()',
        ];
        yield 'uri drops the query string' => [
            ['transaction_naming' => 'uri'],
            '/hello/world?token=secret',
            'GET /hello/world',
        ];
        yield 'service' => [
            ['transaction_naming' => 'service', 'transaction_naming_service' => StaticNamingStrategy::class],
            '/hello/world',
            'custom: /hello/world',
        ];
    }

    /**
     * @param array<string, mixed> $httpConfig
     */
    #[DataProvider('namingStrategies')]
    public function testTransactionNamingStrategies(array $httpConfig, string $uri, string $expectedName): void
    {
        self::bootWithConfig(self::recordingConfig(['http' => $httpConfig]));

        self::request($uri);

        $this->assertSame([[$expectedName]], self::recorder()->callsTo('setTransactionName'));
    }

    public function testUnmatchedRouteIsNamedUnknownAndNotReported(): void
    {
        self::bootWithConfig(self::recordingConfig());

        $response = self::request('/does-not-exist');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([['Unknown Symfony route']], self::recorder()->callsTo('setTransactionName'));
        $this->assertSame([], self::recorder()->callsTo('noticeThrowable'));
    }

    public function testHttpDisabledLeavesTheTransactionAlone(): void
    {
        self::bootWithConfig(self::recordingConfig(['http' => false]));

        self::request('/hello/world');

        $this->assertSame([], self::recorder()->calls());
    }

    public function testControllerExceptionIsReportedExactlyOnce(): void
    {
        self::bootWithConfig(self::recordingConfig());

        $response = self::request('/fail');

        $this->assertSame(500, $response->getStatusCode());

        $noticed = self::recorder()->callsTo('noticeThrowable');
        $this->assertCount(1, $noticed);
        $this->assertInstanceOf(\RuntimeException::class, $noticed[0][0]);
        $this->assertSame('Controller failed', $noticed[0][0]->getMessage());

        // The error page is rendered in a sub-request: the transaction must still be named once, after the route.
        $this->assertSame([['fail']], self::recorder()->callsTo('setTransactionName'));
        $this->assertSame(
            ['addContextFromConfig', 'noticeThrowable', 'setTransactionName', 'addContextFromConfig'],
            self::recorder()->methods(),
        );
    }

    public function testExceptionsDisabledReportsNothing(): void
    {
        self::bootWithConfig(self::recordingConfig(['exceptions' => false]));

        self::request('/fail');

        $this->assertSame([], self::recorder()->callsTo('noticeThrowable'));
        $this->assertSame([['fail']], self::recorder()->callsTo('setTransactionName'));
    }

    /**
     * @return iterable<string, array{string, int|null, bool}>
     */
    public static function statusThresholds(): iterable
    {
        yield '404 below default threshold' => ['/not-found', null, false];
        yield '503 at or above default threshold' => ['/unavailable', null, true];
        yield 'request exception (400) below default threshold' => ['/suspicious', null, false];
        yield '404 captured with threshold 400' => ['/not-found', 400, true];
        yield 'request exception captured with threshold 400' => ['/suspicious', 400, true];
        yield '503 ignored with threshold 504' => ['/unavailable', 504, false];
    }

    #[DataProvider('statusThresholds')]
    public function testCaptureMinStatusCode(string $uri, ?int $threshold, bool $reported): void
    {
        $exceptions = null === $threshold ? [] : ['capture_min_status_code' => $threshold];
        self::bootWithConfig(self::recordingConfig(['exceptions' => $exceptions]));

        self::request($uri);

        $this->assertCount($reported ? 1 : 0, self::recorder()->callsTo('noticeThrowable'));
    }

    public function testIgnoredExceptionsAndTheirSubclassesAreNotReported(): void
    {
        self::bootWithConfig(self::recordingConfig([
            'exceptions' => ['ignored_exceptions' => [IgnoredException::class]],
        ]));

        self::request('/ignored');
        self::request('/ignored-subclass');
        self::request('/fail');

        $noticed = self::recorder()->callsTo('noticeThrowable');
        $this->assertCount(1, $noticed);
        $this->assertInstanceOf(\RuntimeException::class, $noticed[0][0]);
        $this->assertSame('Controller failed', $noticed[0][0]->getMessage());
    }

    public function testDisabledBundleUsesTheBlackholeInteractorEvenWithACustomInteractor(): void
    {
        self::bootWithConfig(self::recordingConfig(['enabled' => false]));

        self::request('/fail');

        $this->assertInstanceOf(
            BlackholeInteractor::class,
            self::getContainer()->get(ElasticApmInteractorInterface::class),
        );
        $this->assertSame([], self::recorder()->calls());
    }

    public function testLoggingDecoratesTheConfiguredInteractor(): void
    {
        self::bootWithConfig(self::recordingConfig(['logging' => true]));

        self::request('/hello/world');

        $this->assertSame([['hello']], self::recorder()->callsTo('setTransactionName'));
        $this->assertContains('Setting Elastic APM transaction name to {name}', self::logger()->messages());
        $this->assertContains('Adding context from config', self::logger()->messages());
    }

    public function testAutoInteractorBootsAndHandlesRequestsInThisEnvironment(): void
    {
        self::bootWithConfig(['interactor' => 'auto', 'deprecations' => false, 'warnings' => false]);

        // Regression: with the extension loaded but the agent disabled, "auto" used to wire
        // ElasticApmInteractor, which fataled on the first request.
        $response = self::request('/fail');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertInstanceOf(
            RealAgent::isUsable() ? ElasticApmInteractor::class : BlackholeInteractor::class,
            self::getContainer()->get(ElasticApmInteractorInterface::class),
        );
    }
}

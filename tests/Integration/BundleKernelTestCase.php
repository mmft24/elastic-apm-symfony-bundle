<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration;

use ElasticApmBundle\ElasticApmBundle;
use ElasticApmBundle\Tests\Integration\Fixtures\FailingCommand;
use ElasticApmBundle\Tests\Integration\Fixtures\OkCommand;
use ElasticApmBundle\Tests\Integration\Fixtures\RecordingInteractor;
use ElasticApmBundle\Tests\Integration\Fixtures\RecordingLogger;
use ElasticApmBundle\Tests\Integration\Fixtures\StaticNamingStrategy;
use ElasticApmBundle\Tests\Integration\Fixtures\TestController;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Boots a real kernel (FrameworkBundle + this bundle) per test, with fixture routes, controllers and commands.
 */
abstract class BundleKernelTestCase extends KernelTestCase
{
    protected const string RECORDING_INTERACTOR = 'test.recording_interactor';
    private mixed $exceptionHandlerBeforeTest = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->exceptionHandlerBeforeTest = self::currentExceptionHandler();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        // Older FrameworkBundle releases (e.g. 6.4.0) register their ErrorHandler as exception handler on boot and
        // never remove it, which PHPUnit flags as risky.
        for ($i = 0; $i < 10 && self::currentExceptionHandler() !== $this->exceptionHandlerBeforeTest; ++$i) {
            \restore_exception_handler();
        }
    }

    #[\Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = parent::createKernel($options);
        \assert($kernel instanceof TestKernel);
        $kernel->handleOptions($options);

        // Kernels booted with the same config compile to the same container class, which is declared only once
        // per process; on Symfony < 8 its lazy-ghost proxy autoloader keeps pointing at the first kernel's cache
        // dir. Deleting that dir on shutdown breaks every later kernel, so keep the caches until the process ends.
        $kernel->setClearCacheAfterShutdown(false);
        self::removeCachesOnExit(\dirname($kernel->getCacheDir()));

        return $kernel;
    }

    /**
     * @param array<string, mixed> $config the elastic_apm configuration
     */
    protected static function bootWithConfig(array $config): KernelInterface
    {
        return static::bootKernel([
            'debug' => false,
            'config' => static function (TestKernel $kernel) use ($config): void {
                $kernel->addTestBundle(ElasticApmBundle::class);
                $kernel->addTestRoutingFile(__DIR__.'/Fixtures/routes.php');
                $kernel->addTestConfig(static function (ContainerBuilder $container) use ($config): void {
                    self::registerFixtures($container);
                    $container->loadFromExtension('elastic_apm', $config);
                });
            },
        ]);
    }

    /**
     * Configuration for the recording interactor. The error-handler listeners are off by default so that
     * deprecations raised while booting the kernel do not show up as interactor calls.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function recordingConfig(array $overrides = []): array
    {
        return \array_replace(
            [
                'interactor' => self::RECORDING_INTERACTOR,
                'deprecations' => false,
                'warnings' => false,
            ],
            $overrides,
        );
    }

    protected static function recorder(): RecordingInteractor
    {
        $recorder = static::getContainer()->get(self::RECORDING_INTERACTOR);
        \assert($recorder instanceof RecordingInteractor);

        return $recorder;
    }

    protected static function logger(): RecordingLogger
    {
        $logger = static::getContainer()->get('logger');
        \assert($logger instanceof RecordingLogger);

        return $logger;
    }

    protected static function request(string $uri): Response
    {
        $kernel = static::$kernel;
        \assert(null !== $kernel);

        $request = Request::create($uri);
        $response = $kernel->handle($request);

        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected static function executeCommand(array $input): int
    {
        $kernel = static::$kernel;
        \assert(null !== $kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        return $application->run(new ArrayInput($input), new BufferedOutput());
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = \set_exception_handler(null);
        \restore_exception_handler();

        return $handler;
    }

    private static function removeCachesOnExit(string $cacheRoot): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;
        \register_shutdown_function(static function () use ($cacheRoot): void {
            (new Filesystem())->remove($cacheRoot);
        });
    }

    private static function registerFixtures(ContainerBuilder $container): void
    {
        $container->register(self::RECORDING_INTERACTOR, RecordingInteractor::class)->setPublic(true);
        $container->register('logger', RecordingLogger::class)->setPublic(true);
        $container->register(StaticNamingStrategy::class, StaticNamingStrategy::class);

        $container->register(TestController::class, TestController::class)
            ->setPublic(true)
            ->addTag('controller.service_arguments');

        $container->register(OkCommand::class, OkCommand::class)
            ->addTag('console.command', ['command' => 'test:ok']);
        $container->register(FailingCommand::class, FailingCommand::class)
            ->addTag('console.command', ['command' => 'test:fail']);
    }
}

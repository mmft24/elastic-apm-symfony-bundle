<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests;

use ElasticApmBundle\DependencyInjection\ElasticApmExtension;
use ElasticApmBundle\ElasticApmBundle;
use ElasticApmBundle\Exception\DeprecationException;
use ElasticApmBundle\Exception\WarningException;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\AbstractErrorHandlerListener;
use ElasticApmBundle\Listener\DeprecationListener;
use ElasticApmBundle\Listener\WarningListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[CoversClass(ElasticApmBundle::class)]
final class ElasticApmBundleTest extends TestCase
{
    private ElasticApmBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new ElasticApmBundle();
    }

    public function testBootRegistersDeprecationListenerIfAvailable(): void
    {
        $deprecationListener = $this->createMock(DeprecationListener::class);
        $deprecationListener->expects($this->once())
            ->method('register');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                [DeprecationListener::class, true],
                [WarningListener::class, false],
            ]);
        $container->expects($this->once())
            ->method('get')
            ->with(DeprecationListener::class)
            ->willReturn($deprecationListener);

        $this->bundle->setContainer($container);
        $this->bundle->boot();
    }

    public function testBootRegistersWarningListenerIfAvailable(): void
    {
        $warningListener = $this->createMock(WarningListener::class);
        $warningListener->expects($this->once())
            ->method('register');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                [DeprecationListener::class, false],
                [WarningListener::class, true],
            ]);
        $container->expects($this->once())
            ->method('get')
            ->with(WarningListener::class)
            ->willReturn($warningListener);

        $this->bundle->setContainer($container);
        $this->bundle->boot();
    }

    public function testBootRegistersBothListenersIfAvailable(): void
    {
        $deprecationListener = $this->createMock(DeprecationListener::class);
        $deprecationListener->expects($this->once())
            ->method('register');

        $warningListener = $this->createMock(WarningListener::class);
        $warningListener->expects($this->once())
            ->method('register');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                [DeprecationListener::class, true],
                [WarningListener::class, true],
            ]);
        $container->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                [DeprecationListener::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $deprecationListener],
                [WarningListener::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $warningListener],
            ]);

        $this->bundle->setContainer($container);
        $this->bundle->boot();
    }

    public function testBootDoesNotRegisterListenersWhenNotAvailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturn(false);
        $container->expects($this->never())
            ->method('get');

        $this->bundle->setContainer($container);
        $this->bundle->boot();
    }

    public function testShutdownUnregistersDeprecationListenerIfAvailable(): void
    {
        $deprecationListener = $this->createMock(DeprecationListener::class);
        $deprecationListener->expects($this->once())
            ->method('unregister');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                [DeprecationListener::class, true],
                [WarningListener::class, false],
            ]);
        $container->expects($this->once())
            ->method('get')
            ->with(DeprecationListener::class)
            ->willReturn($deprecationListener);

        $this->bundle->setContainer($container);
        $this->bundle->shutdown();
    }

    public function testShutdownUnregistersWarningListenerIfAvailable(): void
    {
        $warningListener = $this->createMock(WarningListener::class);
        $warningListener->expects($this->once())
            ->method('unregister');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                [DeprecationListener::class, false],
                [WarningListener::class, true],
            ]);
        $container->expects($this->once())
            ->method('get')
            ->with(WarningListener::class)
            ->willReturn($warningListener);

        $this->bundle->setContainer($container);
        $this->bundle->shutdown();
    }

    public function testShutdownUnregistersBothListenersIfAvailable(): void
    {
        $deprecationListener = $this->createMock(DeprecationListener::class);
        $deprecationListener->expects($this->once())
            ->method('unregister');

        $warningListener = $this->createMock(WarningListener::class);
        $warningListener->expects($this->once())
            ->method('unregister');

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                [DeprecationListener::class, true],
                [WarningListener::class, true],
            ]);
        $container->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                [DeprecationListener::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $deprecationListener],
                [WarningListener::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $warningListener],
            ]);

        $this->bundle->setContainer($container);
        $this->bundle->shutdown();
    }

    public function testShutdownDoesNotUnregisterListenersWhenNotAvailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('has')
            ->willReturn(false);
        $container->expects($this->never())
            ->method('get');

        $this->bundle->setContainer($container);
        $this->bundle->shutdown();
    }

    public function testBootAndShutdownWithRealListenersLeaveHandlerStackUnchanged(): void
    {
        $interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $container = new Container();
        $container->set(DeprecationListener::class, new DeprecationListener($interactor));
        $container->set(WarningListener::class, new WarningListener($interactor));
        $this->bundle->setContainer($container);

        $reported = [];
        $interactor->expects($this->exactly(2))
            ->method('noticeThrowable')
            ->willReturnCallback(static function (\Throwable $e) use (&$reported): void {
                $reported[] = $e::class;
            });

        $this->withBaseHandler(function (\Closure $base): void {
            $this->bundle->boot();
            $this->assertNotSame($base, $this->installedHandler());

            // Both listeners sit on the stack and chain into each other.
            \trigger_error('warning', \E_USER_WARNING);
            \trigger_error('deprecation', \E_USER_DEPRECATED);

            $this->bundle->shutdown();
            $this->assertSame($base, $this->installedHandler());

            \trigger_error('after shutdown', \E_USER_WARNING);
        });

        $this->assertSame([WarningException::class, DeprecationException::class], $reported);
    }

    public function testRepeatedBootShutdownCyclesLeaveHandlerStackUnchanged(): void
    {
        $interactor = $this->createStub(ElasticApmInteractorInterface::class);
        $container = new Container();
        $container->set(DeprecationListener::class, new DeprecationListener($interactor));
        $container->set(WarningListener::class, new WarningListener($interactor));
        $this->bundle->setContainer($container);

        $this->withBaseHandler(function (\Closure $base): void {
            for ($i = 0; $i < 3; ++$i) {
                $this->bundle->boot();
                $this->bundle->boot();
                $this->bundle->shutdown();
                $this->assertSame($base, $this->installedHandler());
            }

            $this->bundle->shutdown();
            $this->assertSame($base, $this->installedHandler());
        });
    }

    public function testCompiledContainerWiresSilencedErrorsFlagIntoBothListeners(): void
    {
        $interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $container = $this->compileContainer(['report_silenced_errors' => true], $interactor);
        $this->bundle->setContainer($container);

        $interactor->expects($this->exactly(2))->method('noticeThrowable');

        $this->withBaseHandler(function (\Closure $base): void {
            $this->bundle->boot();
            @\trigger_error('silenced warning', \E_USER_WARNING);
            @\trigger_error('silenced deprecation', \E_USER_DEPRECATED);
            $this->bundle->shutdown();

            $this->assertSame($base, $this->installedHandler());
        });
    }

    public function testCompiledContainerIgnoresSilencedErrorsByDefault(): void
    {
        $interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $container = $this->compileContainer([], $interactor);
        $this->bundle->setContainer($container);

        $this->assertTrue($container->has('elastic_apm.listener.warning'));
        $this->assertTrue($container->has('elastic_apm.listener.deprecation'));

        $interactor->expects($this->exactly(2))->method('noticeThrowable');

        $this->withBaseHandler(function (): void {
            $this->bundle->boot();
            @\trigger_error('silenced warning', \E_USER_WARNING);
            @\trigger_error('silenced deprecation', \E_USER_DEPRECATED);
            \trigger_error('loud warning', \E_USER_WARNING);
            \trigger_error('loud deprecation', \E_USER_DEPRECATED);
            $this->bundle->shutdown();
        });
    }

    /**
     * Runs $test on top of a swallowing base handler with all error levels enabled, then removes it.
     *
     * @param \Closure(\Closure): void $test
     */
    private function withBaseHandler(\Closure $test): void
    {
        $errorReporting = \error_reporting(\E_ALL);
        $base = static fn(): bool => true;
        \set_error_handler($base);

        try {
            $test($base);
        } finally {
            \restore_error_handler();
            \error_reporting($errorReporting);
        }
    }

    private function installedHandler(): ?callable
    {
        $handler = \set_error_handler(static fn(): bool => false);
        \restore_error_handler();

        return $handler;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compileContainer(array $config, ElasticApmInteractorInterface $interactor): ContainerInterface
    {
        $container = new ContainerBuilder();
        $container->register('test.interactor')->setSynthetic(true)->setPublic(true);
        $container->registerExtension(new ElasticApmExtension());
        $container->loadFromExtension('elastic_apm', [
            'interactor' => 'test.interactor',
            'http' => false,
            'commands' => false,
            'exceptions' => false,
        ] + $config);
        $container->compile();
        $container->set('test.interactor', $interactor);

        foreach ([WarningListener::class, DeprecationListener::class] as $id) {
            $this->assertInstanceOf(AbstractErrorHandlerListener::class, $container->get($id));
        }

        return $container;
    }
}

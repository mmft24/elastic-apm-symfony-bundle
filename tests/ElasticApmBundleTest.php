<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests;

use ElasticApmBundle\ElasticApmBundle;
use ElasticApmBundle\Listener\DeprecationListener;
use ElasticApmBundle\Listener\WarningListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
}

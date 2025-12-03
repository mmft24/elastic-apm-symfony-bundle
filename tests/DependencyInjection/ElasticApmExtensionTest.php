<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\DependencyInjection;

use ElasticApmBundle\DependencyInjection\ElasticApmExtension;
use ElasticApmBundle\Interactor\AdaptiveInteractor;
use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\CommandListener;
use ElasticApmBundle\Listener\DeprecationListener;
use ElasticApmBundle\Listener\ExceptionListener;
use ElasticApmBundle\Listener\FinishRequestListener;
use ElasticApmBundle\Listener\WarningListener;
use ElasticApmBundle\TransactionNamingStrategy\ControllerNamingStrategy;
use ElasticApmBundle\TransactionNamingStrategy\RouteNamingStrategy;
use ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface;
use ElasticApmBundle\TransactionNamingStrategy\UriNamingStrategy;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ElasticApmExtension::class)]
final class ElasticApmExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [
            new ElasticApmExtension(),
        ];
    }

    public function testServicesAreLoaded(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService(Config::class);
        $this->assertContainerBuilderHasService(BlackholeInteractor::class);
        $this->assertContainerBuilderHasService(AdaptiveInteractor::class);
    }

    public function testConfigIsSetCorrectly(): void
    {
        $this->load([
            'custom_labels' => ['label' => 'value'],
            'custom_context' => ['context' => 'value'],
            'track_memory_usage' => true,
            'memory_usage_label' => 'memory',
            'commands' => ['explicitly_collect_exceptions' => true],
            'exceptions' => ['unwrap_exceptions' => false],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            Config::class,
            '$customLabels',
            ['label' => 'value'],
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            Config::class,
            '$customContext',
            ['context' => 'value'],
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            Config::class,
            '$shouldCollectMemoryUsage',
            true,
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            Config::class,
            '$memoryUsageLabelName',
            'memory',
        );
    }

    public function testBlackholeInteractorIsUsedWhenDisabled(): void
    {
        $this->load(['enabled' => false]);

        $this->assertContainerBuilderHasAlias(
            ElasticApmInteractorInterface::class,
            BlackholeInteractor::class,
        );
    }

    public function testAdaptiveInteractorIsUsedByDefault(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias(
            ElasticApmInteractorInterface::class,
            AdaptiveInteractor::class,
        );
    }

    public function testRouteNamingStrategyIsUsedByDefault(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias(
            TransactionNamingStrategyInterface::class,
            RouteNamingStrategy::class,
        );
    }

    public function testControllerNamingStrategyCanBeSet(): void
    {
        $this->load(['http' => ['transaction_naming' => 'controller']]);

        $this->assertContainerBuilderHasAlias(
            TransactionNamingStrategyInterface::class,
            ControllerNamingStrategy::class,
        );
    }

    public function testUriNamingStrategyCanBeSet(): void
    {
        $this->load(['http' => ['transaction_naming' => 'uri']]);

        $this->assertContainerBuilderHasAlias(
            TransactionNamingStrategyInterface::class,
            UriNamingStrategy::class,
        );
    }

    public function testServiceNamingStrategyCanBeSet(): void
    {
        $this->load(
            ['http' => ['transaction_naming' => 'service', 'transaction_naming_service' => 'my.custom.service']],
        );

        $this->assertContainerBuilderHasAlias(
            TransactionNamingStrategyInterface::class,
            'my.custom.service',
        );
    }

    public function testServiceNamingStrategyThrowsExceptionWithoutService(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'When using the "service", transaction naming scheme, the "transaction_naming_service" config parameter must be set.',
        );

        $this->load(['http' => ['transaction_naming' => 'service']]);
    }

    public function testHttpListenerIsLoadedWhenEnabled(): void
    {
        $this->load(['http' => ['enabled' => true]]);

        $this->assertContainerBuilderHasService(FinishRequestListener::class);
    }

    public function testHttpListenerIsNotLoadedWhenDisabled(): void
    {
        $this->load(['http' => ['enabled' => false]]);

        $this->assertContainerBuilderNotHasService(FinishRequestListener::class);
    }

    public function testCommandListenerIsLoadedWhenEnabled(): void
    {
        $this->load(['commands' => ['enabled' => true]]);

        $this->assertContainerBuilderHasService(CommandListener::class);
    }

    public function testCommandListenerIsNotLoadedWhenDisabled(): void
    {
        $this->load(['commands' => ['enabled' => false]]);

        $this->assertContainerBuilderNotHasService(CommandListener::class);
    }

    public function testExceptionListenerIsLoadedWhenEnabled(): void
    {
        $this->load(['exceptions' => ['enabled' => true]]);

        $this->assertContainerBuilderHasService(ExceptionListener::class);
    }

    public function testExceptionListenerIsNotLoadedWhenDisabled(): void
    {
        $this->load(['exceptions' => ['enabled' => false]]);

        $this->assertContainerBuilderNotHasService(ExceptionListener::class);
    }

    public function testExceptionListenerHasIgnoredExceptions(): void
    {
        $this->load(['exceptions' => ['ignored_exceptions' => ['Exception1', 'Exception2']]]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            ExceptionListener::class,
            '$ignoredExceptions',
            ['Exception1', 'Exception2'],
        );
    }

    public function testDeprecationListenerIsLoadedWhenEnabled(): void
    {
        $this->load(['deprecations' => ['enabled' => true]]);

        $this->assertContainerBuilderHasService(DeprecationListener::class);
    }

    public function testDeprecationListenerIsNotLoadedWhenDisabled(): void
    {
        $this->load(['deprecations' => ['enabled' => false]]);

        $this->assertContainerBuilderNotHasService(DeprecationListener::class);
    }

    public function testWarningListenerIsLoadedWhenEnabled(): void
    {
        $this->load(['warnings' => ['enabled' => true]]);

        $this->assertContainerBuilderHasService(WarningListener::class);
    }

    public function testWarningListenerIsNotLoadedWhenDisabled(): void
    {
        $this->load(['warnings' => ['enabled' => false]]);

        $this->assertContainerBuilderNotHasService(WarningListener::class);
    }
}

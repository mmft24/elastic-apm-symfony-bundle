<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\DependencyInjection;

use ElasticApmBundle\DependencyInjection\ElasticApmExtension;
use ElasticApmBundle\Interactor\BlackholeInteractor;
use ElasticApmBundle\Monolog\TraceContextProcessor;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\ProcessorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ElasticApmExtension::class)]
final class LogCorrelationExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [new ElasticApmExtension()];
    }

    public function testProcessorIsNotRegisteredByDefault(): void
    {
        $this->load();

        $this->assertContainerBuilderNotHasService(TraceContextProcessor::class);
        $this->assertContainerBuilderNotHasService('elastic_apm.monolog.trace_context_processor');
    }

    public function testProcessorIsNotRegisteredWhenExplicitlyDisabled(): void
    {
        $this->load(['log_correlation' => ['enabled' => false, 'channels' => ['app']]]);

        $this->assertContainerBuilderNotHasService(TraceContextProcessor::class);
    }

    public function testProcessorIsRegisteredForEveryChannelWhenEnabled(): void
    {
        $this->load(['log_correlation' => true]);

        $this->assertContainerBuilderHasService(TraceContextProcessor::class);
        $this->assertContainerBuilderHasAlias(
            'elastic_apm.monolog.trace_context_processor',
            TraceContextProcessor::class,
        );
        $this->assertSame(
            [[]],
            $this->container->getDefinition(TraceContextProcessor::class)->getTag('monolog.processor'),
        );
    }

    public function testProcessorIsTaggedOncePerChannelAndHandler(): void
    {
        $this->load([
            'log_correlation' => [
                'channels' => ['app', 'messenger'],
                'handlers' => ['main'],
            ],
        ]);

        $this->assertSame(
            [
                ['channel' => 'app'],
                ['channel' => 'messenger'],
                ['handler' => 'main'],
            ],
            $this->container->getDefinition(TraceContextProcessor::class)->getTag('monolog.processor'),
        );
    }

    public function testProcessorCanBeRestrictedToHandlersOnly(): void
    {
        $this->load(['log_correlation' => ['handlers' => ['main', 'console']]]);

        $this->assertSame(
            [['handler' => 'main'], ['handler' => 'console']],
            $this->container->getDefinition(TraceContextProcessor::class)->getTag('monolog.processor'),
        );
    }

    public function testFieldNamesArePassedToTheProcessor(): void
    {
        $this->load([
            'log_correlation' => [
                'field_names' => [
                    'trace_id' => 'apm.trace',
                    'transaction_id' => 'apm.transaction',
                    'span_id' => 'apm.span',
                ],
            ],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            TraceContextProcessor::class,
            '$traceIdField',
            'apm.trace',
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            TraceContextProcessor::class,
            '$transactionIdField',
            'apm.transaction',
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            TraceContextProcessor::class,
            '$spanIdField',
            'apm.span',
        );
    }

    public function testProcessorIsNotAutoconfigured(): void
    {
        // MonologBundle autoconfigures ProcessorInterface with a bare monolog.processor tag, which would
        // silently undo the channel restriction.
        $this->container->registerForAutoconfiguration(ProcessorInterface::class)->addTag('monolog.processor');
        $this->load(['log_correlation' => ['channels' => ['app']]]);

        (new ResolveInstanceofConditionalsPass())->process($this->container);

        $this->assertSame(
            [['channel' => 'app']],
            $this->container->getDefinition(TraceContextProcessor::class)->getTag('monolog.processor'),
        );
    }

    public function testEnablingWithoutMonologFailsWithAnExplicitMessage(): void
    {
        $container = new ContainerBuilder();
        $extension = new ElasticApmExtension(static fn(): bool => false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Monolog 3 is not installed');

        $extension->load([['log_correlation' => true]], $container);
    }

    public function testContainerBuildsWithoutMonologWhenDisabled(): void
    {
        $container = new ContainerBuilder();
        $extension = new ElasticApmExtension(static fn(): bool => false);

        $extension->load([[]], $container);

        $this->assertFalse($container->hasDefinition(TraceContextProcessor::class));
        $this->assertTrue($container->hasDefinition(BlackholeInteractor::class));
    }

    public function testCompiledProcessorCorrelatesRecordsOfARealLogger(): void
    {
        // A fresh builder runs the full optimisation pipeline (autowiring included), unlike $this->container.
        $container = new ContainerBuilder();
        (new ElasticApmExtension())->load([['enabled' => false, 'log_correlation' => true]], $container);
        $container->getAlias('elastic_apm.monolog.trace_context_processor')->setPublic(true);

        $container->compile();

        $processor = $container->get('elastic_apm.monolog.trace_context_processor');
        $this->assertInstanceOf(TraceContextProcessor::class, $processor);

        // With APM disabled the blackhole interactor reports no transaction, so records pass through untouched.
        $handler = new TestHandler();
        (new Logger('app', [$handler], [$processor]))->info('hello');

        $this->assertTrue($handler->hasInfoThatContains('hello'));
        $this->assertSame([], $handler->getRecords()[0]->extra);
    }
}

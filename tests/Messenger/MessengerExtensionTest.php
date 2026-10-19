<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use ElasticApmBundle\DependencyInjection\ElasticApmExtension;
use ElasticApmBundle\Messenger\PropagateTracingListener;
use ElasticApmBundle\Messenger\TracingMiddleware;
use ElasticApmBundle\Messenger\TracingStamper;
use ElasticApmBundle\Messenger\WorkerTransactionListener;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

#[CoversClass(ElasticApmExtension::class)]
final class MessengerExtensionTest extends AbstractExtensionTestCase
{
    private const array SERVICES = [
        TracingStamper::class,
        PropagateTracingListener::class,
        WorkerTransactionListener::class,
        TracingMiddleware::class,
    ];

    protected function getContainerExtensions(): array
    {
        return [new ElasticApmExtension()];
    }

    public function testServicesAreRegisteredByDefault(): void
    {
        $this->load();

        foreach (self::SERVICES as $service) {
            $this->assertContainerBuilderHasService($service);
        }

        $this->assertContainerBuilderHasAlias('elastic_apm.messenger.middleware', TracingMiddleware::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            WorkerTransactionListener::class,
            '$transactionType',
            'message',
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            WorkerTransactionListener::class,
            '$ignoredRetryableExceptions',
            [],
        );
    }

    public function testConfigurationIsPassedToWorkerListener(): void
    {
        $this->load([
            'messenger' => [
                'transaction_type' => 'queue',
                'ignored_retryable_exceptions' => ['App\\ExpectedRetryExceptionInterface'],
            ],
        ]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            WorkerTransactionListener::class,
            '$transactionType',
            'queue',
        );
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            WorkerTransactionListener::class,
            '$ignoredRetryableExceptions',
            ['App\\ExpectedRetryExceptionInterface'],
        );
    }

    public function testServicesAreAbsentWhenDisabled(): void
    {
        $this->load(['messenger' => ['enabled' => false]]);

        foreach (self::SERVICES as $service) {
            $this->assertContainerBuilderNotHasService($service);
        }

        $this->assertContainerBuilderNotHasService('elastic_apm.messenger.middleware');
    }

    public function testCompiledContainerWiresSubscribersAndMiddleware(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new ElasticApmExtension());
        $container->loadFromExtension('elastic_apm', []);
        $container->registerForAutoconfiguration(EventSubscriberInterface::class)
            ->addTag('kernel.event_subscriber');
        $container->setAlias('test.middleware', 'elastic_apm.messenger.middleware')->setPublic(true);

        $subscribers = new \ArrayObject();
        $container->addCompilerPass(
            new readonly class($subscribers) implements CompilerPassInterface {
                /**
                 * @param \ArrayObject<int, string> $subscribers
                 */
                public function __construct(
                    private \ArrayObject $subscribers,
                ) {}

                #[\Override]
                public function process(ContainerBuilder $container): void
                {
                    foreach (\array_keys($container->findTaggedServiceIds('kernel.event_subscriber')) as $id) {
                        $this->subscribers->append($id);
                    }
                }
            },
            PassConfig::TYPE_BEFORE_REMOVING,
        );
        $container->compile(true);

        $this->assertInstanceOf(TracingMiddleware::class, $container->get('test.middleware'));
        $this->assertContains(PropagateTracingListener::class, $subscribers->getArrayCopy());
        $this->assertContains(WorkerTransactionListener::class, $subscribers->getArrayCopy());
    }
}

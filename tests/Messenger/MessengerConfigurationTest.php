<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Messenger;

use ElasticApmBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class MessengerConfigurationTest extends TestCase
{
    // Stamped messages cannot be decoded by consumers without the stamp class, so tracing must be opted into.
    public function testDisabledByDefault(): void
    {
        $config = $this->process(new Configuration(), []);

        $this->assertSame(
            ['enabled' => false, 'transaction_type' => 'message', 'ignored_retryable_exceptions' => []],
            $config['messenger'],
        );
    }

    public function testCanBeEnabled(): void
    {
        $this->assertTrue($this->process(new Configuration(), ['messenger' => true])['messenger']['enabled']);
        $this->assertTrue($this->process(new Configuration(), ['messenger' => null])['messenger']['enabled']);
    }

    public function testCanBeDisabled(): void
    {
        $config = $this->process(new Configuration(), ['messenger' => false]);

        $this->assertFalse($config['messenger']['enabled']);
    }

    // canBeEnabled() semantics: configuring any option enables the feature.
    public function testConfiguringAnOptionEnablesIt(): void
    {
        $config = $this->process(new Configuration(), [
            'messenger' => [
                'transaction_type' => 'queue',
                'ignored_retryable_exceptions' => ['App\\ExpectedRetryExceptionInterface'],
            ],
        ]);

        $this->assertSame(
            [
                'transaction_type' => 'queue',
                'ignored_retryable_exceptions' => ['App\\ExpectedRetryExceptionInterface'],
                'enabled' => true,
            ],
            $config['messenger'],
        );
    }

    public function testTransactionTypeCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(new Configuration(), ['messenger' => ['transaction_type' => '']]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(Configuration $configuration, array $config): array
    {
        /** @var array<string, mixed> */
        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}

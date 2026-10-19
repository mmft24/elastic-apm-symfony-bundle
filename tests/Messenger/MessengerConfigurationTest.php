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
    public function testEnabledByDefaultWhenMessengerIsInstalled(): void
    {
        $config = $this->process(new Configuration(), []);

        $this->assertSame(
            ['enabled' => true, 'transaction_type' => 'message', 'ignored_retryable_exceptions' => []],
            $config['messenger'],
        );
    }

    public function testDetectsMessengerByDefault(): void
    {
        $this->assertTrue($this->process(new Configuration(), [])['messenger']['enabled']);
    }

    public function testDisabledByDefaultWhenMessengerIsMissing(): void
    {
        $config = $this->process(new Configuration(messengerAvailable: false), []);

        $this->assertFalse($config['messenger']['enabled']);
    }

    public function testCanBeDisabled(): void
    {
        $config = $this->process(new Configuration(), ['messenger' => false]);

        $this->assertFalse($config['messenger']['enabled']);
    }

    public function testCanBeConfigured(): void
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

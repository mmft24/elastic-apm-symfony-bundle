<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\DependencyInjection;

use ElasticApmBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    private Configuration $configuration;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
        $this->processor = new Processor();
    }

    public function testDefaultConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        $this->assertTrue($config['enabled']);
        $this->assertFalse($config['logging']);
        $this->assertFalse($config['track_memory_usage']);
        $this->assertSame('memory_usage', $config['memory_usage_label']);
        $this->assertTrue($config['exceptions']['enabled']);
        $this->assertSame([], $config['exceptions']['ignored_exceptions']);
        $this->assertFalse($config['exceptions']['unwrap_exceptions']);
        $this->assertTrue($config['deprecations']['enabled']);
        $this->assertTrue($config['warnings']['enabled']);
        $this->assertSame([], $config['custom_labels']);
        $this->assertSame([], $config['custom_context']);
        $this->assertTrue($config['http']['enabled']);
        $this->assertSame('route', $config['http']['transaction_naming']);
        $this->assertNull($config['http']['transaction_naming_service']);
        $this->assertTrue($config['commands']['enabled']);
        $this->assertTrue($config['commands']['explicitly_collect_exceptions']);
    }

    public function testEnabledCanBeSetToFalse(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['enabled' => false],
        ]);

        $this->assertFalse($config['enabled']);
    }

    public function testLoggingCanBeEnabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['logging' => true],
        ]);

        $this->assertTrue($config['logging']);
    }

    public function testTrackMemoryUsageCanBeEnabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['track_memory_usage' => true],
        ]);

        $this->assertTrue($config['track_memory_usage']);
    }

    public function testMemoryUsageLabelCanBeCustomized(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['memory_usage_label' => 'custom_memory_label'],
        ]);

        $this->assertSame('custom_memory_label', $config['memory_usage_label']);
    }

    public function testInteractorCanBeSet(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['interactor' => 'auto'],
        ]);

        $this->assertSame('auto', $config['interactor']);
    }

    public function testCustomLabelsCanBeSet(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['custom_labels' => ['label1' => 'value1', 'label2' => 'value2']],
        ]);

        $this->assertSame(['label1' => 'value1', 'label2' => 'value2'], $config['custom_labels']);
    }

    public function testCustomContextCanBeSet(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['custom_context' => ['context1' => 'value1', 'context2' => 'value2']],
        ]);

        $this->assertSame(['context1' => 'value1', 'context2' => 'value2'], $config['custom_context']);
    }

    public function testExceptionsCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['exceptions' => ['enabled' => false]],
        ]);

        $this->assertFalse($config['exceptions']['enabled']);
    }

    public function testIgnoredExceptionsCanBeSet(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['exceptions' => ['ignored_exceptions' => ['Exception1', 'Exception2']]],
        ]);

        $this->assertSame(['Exception1', 'Exception2'], $config['exceptions']['ignored_exceptions']);
    }

    public function testUnwrapExceptionsCanBeEnabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['exceptions' => ['unwrap_exceptions' => true]],
        ]);

        $this->assertTrue($config['exceptions']['unwrap_exceptions']);
    }

    public function testDeprecationsCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['deprecations' => ['enabled' => false]],
        ]);

        $this->assertFalse($config['deprecations']['enabled']);
    }

    public function testWarningsCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['warnings' => ['enabled' => false]],
        ]);

        $this->assertFalse($config['warnings']['enabled']);
    }

    public function testHttpCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['http' => ['enabled' => false]],
        ]);

        $this->assertFalse($config['http']['enabled']);
    }

    #[DataProvider('validTransactionNamingProvider')]
    public function testTransactionNamingCanBeSet(string $naming): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['http' => ['transaction_naming' => $naming]],
        ]);

        $this->assertSame($naming, $config['http']['transaction_naming']);
    }

    /**
     * @return array<array<int, string>>
     */
    public static function validTransactionNamingProvider(): array
    {
        return [
            ['uri'],
            ['route'],
            ['controller'],
            ['service'],
        ];
    }

    public function testInvalidTransactionNamingThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->configuration, [
            ['http' => ['transaction_naming' => 'invalid']],
        ]);
    }

    public function testTransactionNamingServiceCanBeSet(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['http' => ['transaction_naming_service' => 'my.custom.service']],
        ]);

        $this->assertSame('my.custom.service', $config['http']['transaction_naming_service']);
    }

    public function testCommandsCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['commands' => ['enabled' => false]],
        ]);

        $this->assertFalse($config['commands']['enabled']);
    }

    public function testExplicitlyCollectExceptionsCanBeDisabled(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            ['commands' => ['explicitly_collect_exceptions' => false]],
        ]);

        $this->assertFalse($config['commands']['explicitly_collect_exceptions']);
    }
}

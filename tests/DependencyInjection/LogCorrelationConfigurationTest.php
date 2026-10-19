<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\DependencyInjection;

use ElasticApmBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class LogCorrelationConfigurationTest extends TestCase
{
    public function testIsDisabledByDefaultWithEcsFieldNames(): void
    {
        $config = $this->process([]);

        $this->assertSame(
            [
                'enabled' => false,
                'channels' => [],
                'handlers' => [],
                'field_names' => [
                    'trace_id' => 'trace.id',
                    'transaction_id' => 'transaction.id',
                    'span_id' => 'span.id',
                ],
            ],
            $config['log_correlation'],
        );
    }

    public function testIsUnrelatedToTheLoggingOption(): void
    {
        $config = $this->process(['logging' => true]);

        $this->assertTrue($config['logging']);
        $this->assertFalse($config['log_correlation']['enabled']);
    }

    public function testCanBeEnabledWithShorthand(): void
    {
        $config = $this->process(['log_correlation' => true]);

        $this->assertTrue($config['log_correlation']['enabled']);
    }

    public function testAcceptsChannelsHandlersAndFieldNames(): void
    {
        $config = $this->process([
            'log_correlation' => [
                'channels' => ['app', 'messenger'],
                'handlers' => ['main'],
                'field_names' => ['span_id' => 'apm.span_id'],
            ],
        ]);

        $this->assertTrue($config['log_correlation']['enabled']);
        $this->assertSame(['app', 'messenger'], $config['log_correlation']['channels']);
        $this->assertSame(['main'], $config['log_correlation']['handlers']);
        $this->assertSame('trace.id', $config['log_correlation']['field_names']['trace_id']);
        $this->assertSame('transaction.id', $config['log_correlation']['field_names']['transaction_id']);
        $this->assertSame('apm.span_id', $config['log_correlation']['field_names']['span_id']);
    }

    public function testRejectsEmptyFieldName(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['log_correlation' => ['field_names' => ['trace_id' => '']]]);
    }

    public function testRejectsDuplicateFieldNames(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('field names must be distinct');

        $this->process(['log_correlation' => ['field_names' => ['span_id' => 'transaction.id']]]);
    }

    public function testRejectsEmptyChannelName(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['log_correlation' => ['channels' => ['']]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}

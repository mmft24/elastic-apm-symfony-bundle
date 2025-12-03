<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Interactor;

use ElasticApmBundle\Exception\ConfigurationException;
use ElasticApmBundle\Interactor\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testConstructorCreatesConfigWithValidData(): void
    {
        $config = new Config(
            ['label1' => 'value1'],
            ['context1' => 'value1'],
            true,
            'memory_label',
            true,
            false,
        );

        $this->assertSame(['label1' => 'value1'], $config->getCustomLabels());
        $this->assertSame(['context1' => 'value1'], $config->getCustomContext());
        $this->assertTrue($config->shouldCollectMemoryUsage());
        $this->assertSame('memory_label', $config->getMemoryUsageLabelName());
        $this->assertTrue($config->shouldExplicitlyCollectCommandExceptions());
        $this->assertFalse($config->shouldUnwrapExceptions());
    }

    public function testConstructorThrowsExceptionForEmptyMemoryUsageLabelName(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('$memoryUsageLabelName cannot be blank');

        new Config([], [], false, '', false, false);
    }

    public function testSetCustomLabels(): void
    {
        $config = new Config([], [], false, 'memory', false, false);

        $config->setCustomLabels(['label1' => 'value1', 'label2' => 'value2']);

        $this->assertSame(['label1' => 'value1', 'label2' => 'value2'], $config->getCustomLabels());
    }

    public function testAddCustomLabels(): void
    {
        $config = new Config(['existing' => 'label'], [], false, 'memory', false, false);

        $config->addCustomLabels('new', 'value');

        $this->assertSame(['existing' => 'label', 'new' => 'value'], $config->getCustomLabels());
    }

    #[DataProvider('scalarValuesProvider')]
    public function testAddCustomLabelsAcceptsScalarValues(mixed $value): void
    {
        $config = new Config([], [], false, 'memory', false, false);

        $config->addCustomLabels('key', $value);

        $this->assertSame(['key' => $value], $config->getCustomLabels());
    }

    public function testSetCustomContext(): void
    {
        $config = new Config([], [], false, 'memory', false, false);

        $config->setCustomContext(['context1' => 'value1', 'context2' => 'value2']);

        $this->assertSame(['context1' => 'value1', 'context2' => 'value2'], $config->getCustomContext());
    }

    public function testAddCustomContext(): void
    {
        $config = new Config([], ['existing' => 'context'], false, 'memory', false, false);

        $config->addCustomContext('new', 'value');

        $this->assertSame(['existing' => 'context', 'new' => 'value'], $config->getCustomContext());
    }

    #[DataProvider('scalarValuesProvider')]
    public function testAddCustomContextAcceptsScalarValues(mixed $value): void
    {
        $config = new Config([], [], false, 'memory', false, false);

        $config->addCustomContext('key', $value);

        $this->assertSame(['key' => $value], $config->getCustomContext());
    }

    /**
     * @return array<string, array<int, string|int|float|bool>>
     */
    public static function scalarValuesProvider(): array
    {
        return [
            'string' => ['string_value'],
            'integer' => [42],
            'float' => [3.14],
            'boolean true' => [true],
            'boolean false' => [false],
        ];
    }
}

<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Exception;

use ElasticApmBundle\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationException::class)]
final class ConfigurationExceptionTest extends TestCase
{
    public function testExceptionCanBeCreated(): void
    {
        $exception = new ConfigurationException('Test message', 0, \E_ERROR, '/path/to/file.php', 42);

        $this->assertInstanceOf(\ErrorException::class, $exception);
        $this->assertInstanceOf(ConfigurationException::class, $exception);
        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame(\E_ERROR, $exception->getSeverity());
        $this->assertSame('/path/to/file.php', $exception->getFile());
        $this->assertSame(42, $exception->getLine());
    }

    public function testExceptionCanBeThrown(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration error');

        throw new ConfigurationException('Configuration error');
    }
}

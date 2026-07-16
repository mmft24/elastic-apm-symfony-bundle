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
        $previous = new \RuntimeException('cause');
        $exception = new ConfigurationException('Test message', 7, $previous);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(7, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionIsAnInvalidArgumentException(): void
    {
        // Configuration preconditions are argument-validation failures, not PHP errors,
        // so the exception must be catchable as an \InvalidArgumentException.
        $caught = null;

        try {
            throw new ConfigurationException('bad config');
        } catch (\InvalidArgumentException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ConfigurationException::class, $caught);
    }

    public function testExceptionCanBeThrown(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration error');

        throw new ConfigurationException('Configuration error');
    }
}

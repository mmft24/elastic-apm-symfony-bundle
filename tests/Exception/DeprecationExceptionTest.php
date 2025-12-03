<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Exception;

use ElasticApmBundle\Exception\DeprecationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeprecationException::class)]
final class DeprecationExceptionTest extends TestCase
{
    public function testExceptionCanBeCreated(): void
    {
        $exception = new DeprecationException('Deprecated feature', 0, \E_USER_DEPRECATED, '/path/to/file.php', 10);

        $this->assertInstanceOf(\ErrorException::class, $exception);
        $this->assertInstanceOf(DeprecationException::class, $exception);
        $this->assertSame('Deprecated feature', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame(\E_USER_DEPRECATED, $exception->getSeverity());
        $this->assertSame('/path/to/file.php', $exception->getFile());
        $this->assertSame(10, $exception->getLine());
    }

    public function testExceptionCanBeThrown(): void
    {
        $this->expectException(DeprecationException::class);
        $this->expectExceptionMessage('This is deprecated');

        throw new DeprecationException('This is deprecated');
    }
}

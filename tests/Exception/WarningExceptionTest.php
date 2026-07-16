<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Exception;

use ElasticApmBundle\Exception\WarningException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WarningException::class)]
final class WarningExceptionTest extends TestCase
{
    public function testExceptionCanBeCreated(): void
    {
        $exception = new WarningException('Warning message', 0, \E_WARNING, '/path/to/file.php', 20);

        // getSeverity()/getFile()/getLine() below exercise the \ErrorException contract this class relies on.
        $this->assertSame('Warning message', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame(\E_WARNING, $exception->getSeverity());
        $this->assertSame('/path/to/file.php', $exception->getFile());
        $this->assertSame(20, $exception->getLine());
    }

    public function testExceptionCanBeThrown(): void
    {
        $this->expectException(WarningException::class);
        $this->expectExceptionMessage('A warning occurred');

        throw new WarningException('A warning occurred');
    }
}

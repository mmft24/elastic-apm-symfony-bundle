<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\TransactionNamingStrategy;

use ElasticApmBundle\TransactionNamingStrategy\UriNamingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(UriNamingStrategy::class)]
final class UriNamingStrategyTest extends TestCase
{
    private UriNamingStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new UriNamingStrategy();
    }

    public function testGetTransactionNameReturnsMethodAndUri(): void
    {
        $request = Request::create('/api/users', 'GET');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('GET /api/users', $result);
    }

    public function testGetTransactionNameWithPostMethod(): void
    {
        $request = Request::create('/api/users', 'POST');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('POST /api/users', $result);
    }

    public function testGetTransactionNameWithQueryString(): void
    {
        $request = Request::create('/api/users?sort=name&limit=10', 'GET');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('GET /api/users?sort=name&limit=10', $result);
    }

    public function testGetTransactionNameWithRootPath(): void
    {
        $request = Request::create('/', 'GET');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('GET /', $result);
    }

    public function testGetTransactionNameWithDeleteMethod(): void
    {
        $request = Request::create('/api/users/123', 'DELETE');

        $result = $this->strategy->getTransactionName($request);

        $this->assertSame('DELETE /api/users/123', $result);
    }
}

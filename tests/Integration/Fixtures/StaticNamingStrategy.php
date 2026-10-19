<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration\Fixtures;

use ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

final class StaticNamingStrategy implements TransactionNamingStrategyInterface
{
    #[\Override]
    public function getTransactionName(Request $request): string
    {
        return 'custom: '.$request->getPathInfo();
    }
}

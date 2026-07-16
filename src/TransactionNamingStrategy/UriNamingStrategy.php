<?php

declare(strict_types=1);

/*
 * This file is part of the Elastic APM Symfony Bundle.
 *
 * (c) mmft24
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ElasticApmBundle\TransactionNamingStrategy;

use Symfony\Component\HttpFoundation\Request;

final class UriNamingStrategy implements TransactionNamingStrategyInterface
{
    #[\Override]
    public function getTransactionName(Request $request): string
    {
        // Use the path only and drop the query string. The query string can carry
        // sensitive data (tokens, e-mail addresses, session ids) that must never be
        // written verbatim into an APM transaction name.
        return "{$request->getMethod()} {$request->getPathInfo()}";
    }
}

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

namespace ElasticApmBundle\Listener;

use ElasticApmBundle\Exception\DeprecationException;

class DeprecationListener extends AbstractErrorHandlerListener
{
    #[\Override]
    protected function handles(int $type): bool
    {
        return \E_USER_DEPRECATED === $type;
    }

    #[\Override]
    protected function createThrowable(int $type, string $message, string $file, int $line): \Throwable
    {
        return new DeprecationException($message, 0, $type, $file, $line);
    }
}

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

use ElasticApmBundle\Exception\WarningException;

/**
 * Left open so applications can subclass it, e.g. overriding shouldReport() to drop known-noisy messages.
 *
 * @api
 */
class WarningListener extends AbstractErrorHandlerListener
{
    #[\Override]
    protected function handles(int $type): bool
    {
        return \E_WARNING === $type || \E_USER_WARNING === $type;
    }

    #[\Override]
    protected function createThrowable(int $type, string $message, string $file, int $line): \Throwable
    {
        return new WarningException($message, 0, $type, $file, $line);
    }
}

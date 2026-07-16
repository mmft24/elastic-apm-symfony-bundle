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

namespace ElasticApmBundle;

use ElasticApmBundle\Listener\AbstractErrorHandlerListener;
use ElasticApmBundle\Listener\DeprecationListener;
use ElasticApmBundle\Listener\WarningListener;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ElasticApmBundle extends Bundle
{
    #[\Override]
    public function boot(): void
    {
        parent::boot();

        $this->register(DeprecationListener::class);
        $this->register(WarningListener::class);
    }

    #[\Override]
    public function shutdown(): void
    {
        // Error handlers form a LIFO stack, and restore_error_handler() always pops the top one. Unregister in
        // the reverse order of boot() (Warning was registered last, so it is on top) so each listener removes its
        // own handler rather than a sibling's.
        $this->unregister(WarningListener::class);
        $this->unregister(DeprecationListener::class);

        parent::shutdown();
    }

    /**
     * @param class-string<AbstractErrorHandlerListener> $listenerId
     */
    private function register(string $listenerId): void
    {
        $this->resolveListener($listenerId)?->register();
    }

    /**
     * @param class-string<AbstractErrorHandlerListener> $listenerId
     */
    private function unregister(string $listenerId): void
    {
        $this->resolveListener($listenerId)?->unregister();
    }

    /**
     * @param class-string<AbstractErrorHandlerListener> $listenerId
     */
    private function resolveListener(string $listenerId): ?AbstractErrorHandlerListener
    {
        if (null === $this->container || !$this->container->has($listenerId)) {
            return null;
        }

        $listener = $this->container->get($listenerId);

        return $listener instanceof AbstractErrorHandlerListener ? $listener : null;
    }
}

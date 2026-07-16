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

use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Shared machinery for listeners that hook into PHP's error handler to forward specific error levels to
 * Elastic APM as throwables.
 *
 * Concrete listeners decide which error levels they handle and how to turn an error into a throwable. This base
 * class deduplicates events so a hot code path that emits the same error over and over does not flood APM with
 * identical entries.
 *
 * The dedup cache is scoped to a single transaction: it implements {@see ResetInterface} so that Symfony's
 * services resetter clears it between HTTP requests (including worker runtimes such as RoadRunner or FrankenPHP)
 * and between Messenger messages. Without this, the error handler is registered once per process and the cache
 * would live for the whole worker lifetime, silently dropping an error seen in one message from every subsequent
 * message that emits it.
 */
abstract class AbstractErrorHandlerListener implements ResetInterface
{
    /**
     * Upper bound on the dedup cache so a long-running process cannot grow it without limit.
     */
    private const int MAX_DEDUP_ENTRIES = 1000;
    private bool $isRegistered = false;

    /**
     * @var array<string, true>
     */
    private array $seen = [];

    public function __construct(
        protected readonly ElasticApmInteractorInterface $interactor,
    ) {}

    public function register(): void
    {
        if ($this->isRegistered) {
            return;
        }
        $this->isRegistered = true;

        /** @psalm-suppress UndefinedVariable $prevErrorHandler */
        $prevErrorHandler = \set_error_handler(function (int $type, string $msg, string $file, int $line) use (
            &$prevErrorHandler
        ): bool {
            if ($this->handles($type) && !$this->isDuplicate($type, $msg, $file, $line)) {
                $this->interactor->addContextFromConfig();
                $this->interactor->noticeThrowable($this->createThrowable($type, $msg, $file, $line));
            }

            return $prevErrorHandler ? (bool) $prevErrorHandler($type, $msg, $file, $line) : false;
        });
    }

    public function unregister(): void
    {
        if (!$this->isRegistered) {
            return;
        }
        $this->isRegistered = false;
        \restore_error_handler();
    }

    /**
     * Clears the dedup cache at each transaction boundary. Called by Symfony's services resetter between HTTP
     * requests and Messenger messages so that an error reported in one transaction is reported again in the next.
     */
    #[\Override]
    public function reset(): void
    {
        $this->seen = [];
    }

    /**
     * Whether the given error level should be forwarded to APM.
     */
    abstract protected function handles(int $type): bool;

    /**
     * Builds the throwable that represents the captured error.
     */
    abstract protected function createThrowable(int $type, string $message, string $file, int $line): \Throwable;

    private function isDuplicate(int $type, string $message, string $file, int $line): bool
    {
        $key = $type.'|'.$file.'|'.$line.'|'.$message;

        if (isset($this->seen[$key])) {
            return true;
        }

        // Evict the oldest entry once at capacity rather than wiping the whole cache, so a flood of distinct
        // errors cannot cause every previously-seen error to be re-reported at once. PHP arrays keep insertion
        // order, so the first key is the oldest.
        if (\count($this->seen) >= self::MAX_DEDUP_ENTRIES) {
            unset($this->seen[\array_key_first($this->seen)]);
        }

        $this->seen[$key] = true;

        return false;
    }
}

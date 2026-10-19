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
 * Warnings silenced with the `@` operator or excluded by error_reporting() are not reported by default: PHP still
 * invokes user error handlers for them, so without this check every `@`-guarded call would end up in APM.
 * Deprecations are exempt, like in Symfony's ErrorHandler: trigger_deprecation() and doctrine/deprecations always
 * emit them with `@`, so honouring the silencing would drop virtually all of them.
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
    private bool $reportSilencedErrors = false;
    private ?\Closure $previousHandler = null;

    /**
     * The closure installed with set_error_handler(), kept while it is still part of PHP's handler stack.
     */
    private ?\Closure $installedHandler = null;

    /**
     * @var array<string, true>
     */
    private array $seen = [];

    public function __construct(
        protected readonly ElasticApmInteractorInterface $interactor,
    ) {}

    /**
     * Also report warnings silenced with `@` or excluded by error_reporting(). Deprecations are always reported.
     */
    public function setReportSilencedErrors(bool $reportSilencedErrors): void
    {
        $this->reportSilencedErrors = $reportSilencedErrors;
    }

    public function register(): void
    {
        if ($this->isRegistered) {
            return;
        }
        $this->isRegistered = true;

        // Still in the stack after an unregister() that could not pop it: reactivate instead of stacking a copy.
        if (null !== $this->installedHandler) {
            return;
        }

        $this->installedHandler = $this->handleError(...);
        $previousHandler = \set_error_handler($this->installedHandler);
        $this->previousHandler = null === $previousHandler ? null : \Closure::fromCallable($previousHandler);
    }

    /**
     * Stops reporting and removes the handler from PHP's stack when it is on top.
     *
     * restore_error_handler() always pops the top handler, so when another handler was registered after this one
     * popping would remove that handler instead. The handler then stays installed as a pure pass-through to the
     * previous handler, keeping the chain intact; a later unregister() removes it once it is on top again.
     */
    public function unregister(): void
    {
        $this->isRegistered = false;

        if (null === $this->installedHandler) {
            return;
        }

        $top = \set_error_handler(static fn(): bool => false);
        \restore_error_handler();

        if ($top !== $this->installedHandler) {
            return;
        }

        \restore_error_handler();
        $this->installedHandler = null;
        $this->previousHandler = null;
    }

    /**
     * Error handler installed by {@see register()}.
     *
     * The previous handler's return value is passed through untouched: PHP runs its built-in handler (which logs
     * the error and populates error_get_last()) only when a user handler returns exactly `false`, so collapsing
     * e.g. `null` to a bool would change behaviour for the rest of the chain. Without a previous handler `false`
     * is returned so PHP's built-in handling still happens.
     */
    public function handleError(int $type, string $message, string $file = '', int $line = 0): mixed
    {
        if ($this->isRegistered && $this->shouldReport($type, $message, $file, $line) && !$this->isDuplicate(
            $type,
            $message,
            $file,
            $line
        )) {
            $this->interactor->addContextFromConfig();
            $this->interactor->noticeThrowable($this->createThrowable($type, $message, $file, $line));
        }

        return null === $this->previousHandler ? false : ($this->previousHandler)($type, $message, $file, $line);
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
     * Extension point for subclasses that need extra filtering (e.g. ignoring messages or paths); call the parent
     * to keep the level and silencing checks.
     */
    protected function shouldReport(int $type, string $message, string $file, int $line): bool
    {
        if (!$this->handles($type)) {
            return false;
        }

        return $this->reportSilencedErrors || self::isDeprecation($type) || !self::isSilenced($type);
    }

    /**
     * Whether the error was silenced with `@` or excluded by the current error_reporting() level. Since PHP 8 the
     * `@` operator lowers error_reporting() to fatal levels only rather than to 0.
     */
    protected static function isSilenced(int $type): bool
    {
        return 0 === (\error_reporting() & $type);
    }

    /**
     * Symfony's ErrorHandler never treats deprecations as silenced, neither by `@` nor by error_reporting().
     */
    protected static function isDeprecation(int $type): bool
    {
        return 0 !== ($type & (\E_DEPRECATED | \E_USER_DEPRECATED));
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

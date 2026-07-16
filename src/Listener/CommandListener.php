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

use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class CommandListener implements EventSubscriberInterface
{
    /**
     * Whole-word tokens (case-insensitive) that mark an option/argument as sensitive. A name is matched by
     * splitting it into words on separators (`-`, `_`) and camelCase boundaries and comparing each word
     * against this list, so `--ssh-key`, `--api_key` and `apiKey` all match `key`/`apikey` while
     * `--author` and `--authority` are *not* dragged in by a bare `auth` substring. The value of any
     * matching option/argument is replaced with a placeholder before being sent to APM so that credentials
     * such as `--password`, `--token` or a database DSN are never leaked into the trace.
     *
     * Name-based redaction is a best-effort safety net, not a guarantee: a secret passed through an
     * unrecognised name still leaks. Extend the list per project via the
     * `elastic_apm.commands.sensitive_parameter_names` configuration key.
     *
     * @var list<string>
     */
    private const array SENSITIVE_NAME_PATTERNS = [
        'password', 'passwd', 'pwd', 'passphrase',
        'secret', 'secrets',
        'token', 'jwt', 'bearer',
        'key', 'keys', 'apikey',
        'auth', 'credential', 'credentials',
        'dsn',
    ];

    private const string REDACTED_PLACEHOLDER = '[REDACTED]';

    /**
     * The default tokens merged with any project-specific names, all lowercased and de-duplicated.
     *
     * @var list<string>
     */
    private array $sensitivePatterns;

    /**
     * @param list<string> $additionalSensitiveParameterNames extra option/argument names whose values must
     *                                                         be redacted (matched as whole words)
     */
    public function __construct(
        private ElasticApmInteractorInterface $interactor,
        private Config $config,
        array $additionalSensitiveParameterNames = [],
    ) {
        $patterns = self::SENSITIVE_NAME_PATTERNS;

        foreach ($additionalSensitiveParameterNames as $name) {
            // Normalise user-supplied names through the same tokenizer so "private-key", "private_key"
            // and "privateKey" all contribute the same matchable words.
            foreach ($this->tokenize($name) as $token) {
                $patterns[] = $token;
            }
        }

        $this->sensitivePatterns = \array_values(\array_unique($patterns));
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', 0],
            ConsoleEvents::ERROR => ['onConsoleError', 0],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $input = $event->getInput();

        // getCommand() and getName() are both nullable. An instrumentation listener must never crash the
        // command it observes, so default the name instead of dereferencing a possible null.
        $this->interactor->setTransactionName($command?->getName() ?? 'unknown command');

        foreach ($input->getOptions() as $name => $value) {
            $this->addParameterContext('--'.$name, $name, $value);
        }

        foreach ($input->getArguments() as $name => $value) {
            $this->addParameterContext($name, $name, $value);
        }

        $this->interactor->addContextFromConfig();
    }

    /**
     * @param string $contextKey the key written to APM (options are prefixed with "--")
     * @param string $rawName    the bare option/argument name, used for sensitivity matching
     */
    private function addParameterContext(string $contextKey, string $rawName, mixed $value): void
    {
        if ($this->isSensitive($rawName)) {
            $this->interactor->addCustomContext($contextKey, self::REDACTED_PLACEHOLDER);

            return;
        }

        if (\is_array($value)) {
            foreach ($value as $index => $item) {
                $this->interactor->addCustomContext($contextKey.'['.$index.']', $item);
            }

            return;
        }

        $this->interactor->addCustomContext($contextKey, $value);
    }

    private function isSensitive(string $name): bool
    {
        foreach ($this->tokenize($name) as $token) {
            if (\in_array($token, $this->sensitivePatterns, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split an option/argument name into lowercased words on separators (`-`, `_`, and any other
     * non-alphanumeric character) and camelCase boundaries.
     *
     * @return list<string>
     */
    private function tokenize(string $name): array
    {
        $tokens = \preg_split('/(?<=[a-z0-9])(?=[A-Z])|[^A-Za-z0-9]+/', $name, -1, \PREG_SPLIT_NO_EMPTY);

        return \array_map(\strtolower(...), $tokens ?: []);
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        if (!$this->config->shouldExplicitlyCollectCommandExceptions()) {
            return;
        }

        $this->interactor->addContextFromConfig();

        // noticeThrowable() already walks the whole getPrevious() chain when exception unwrapping is
        // enabled, so reporting the cause here as well would double-report every nested exception.
        $this->interactor->noticeThrowable($event->getError());
    }
}

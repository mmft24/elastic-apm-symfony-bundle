Elastic APM Symfony Bundle
=====================

[![Latest Version](https://img.shields.io/github/release/mmft24/elastic-apm-symfony-bundle.svg?style=flat-square)](https://github.com/mmft24/elastic-apm-symfony-bundle/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/mmft24/elastic-apm-symfony-bundle.svg?style=flat-square)](https://packagist.org/packages/mmft24/elastic-apm-symfony-bundle)
[![Tests](https://github.com/mmft24/elastic-apm-symfony-bundle/workflows/Tests/badge.svg)](https://github.com/mmft24/elastic-apm-symfony-bundle/actions)

This bundle integrates the Elastic APM PHP API into Symfony. For more information about Elastic APM, please visit https://www.elastic.co/apm. This bundle adds a lot more essentials. Here's a quick list:

1. **Better transaction naming strategy**: Your transaction traces can be named accurately by route names, the controller name, the request URI or you can decide on a custom naming strategy via a seamless interface that uses any naming convention you deem fit. While running console commands, it also sets the transaction name as the command name.

2. **Console Commands Enhancements**: While running console commands, it sets the options and arguments passed via the CLI as custom parameters to the transaction trace for easier debugging (sensitive values are redacted), and sets the transaction result (`CLI <exit code>`) and outcome.

3. **Exception Listening**: It also captures all Symfony exceptions in web requests and console commands and sends them to Elastic APM.

4. **Interactor Service**: It provides access to most of the Elastic APM API via a Service class `ElasticApmInteractorInterface::class`. This can be injected into any class, controller or service to communicate with APM. If the default `AdaptiveInteractor` is used then any APM calls will be ignored when the extension isn't loaded (for example in development environments).

    ```php
    $this->apm->addLabel('name', 'john');
    ```

5. **Logging Support**: In development, you are unlikely to have Elastic APM setup. There's a configuration to enable logging which outputs all actions to your Symfony log, hence emulating what it would actually do in production.

6. **Warnings and deprecations**: PHP warnings and deprecations are reported to APM, deduplicated per request.

7. **Symfony Messenger tracing** (opt-in): traces flow from the producer to the worker, and each consumed message becomes its own transaction.

8. **Log ↔ trace correlation** (opt-in): Monolog records carry `trace.id`, `transaction.id` and `span.id` so Kibana can link logs and traces.

## Requirements

- PHP 8.3+
- Symfony 6.4, 7.x or 8.x
- The [`elastic_apm` PHP extension](https://www.elastic.co/guide/en/apm/agent/php/current/intro.html) to actually send data (without it the bundle falls back to a no-op interactor)
- Optional: `symfony/messenger` for Messenger tracing, `monolog/monolog` ^3 for log correlation

## Upgrading from 2.x

3.0 contains a few breaking and behaviour changes; see the [CHANGELOG](CHANGELOG.md) for the full list. In short:

- `ElasticApmInteractorInterface` has a new method, `beginCurrentTransactionFromHeaders()`. Custom interactors must
  implement it.
- `ConfigurationException` now extends `\InvalidArgumentException` instead of `\ErrorException`.
- The warning and deprecation listeners now actually report (they never registered their error handlers in 2.0.0).
  Set `warnings: false` / `deprecations: false` to keep them out of APM.
- With `transaction_naming: controller`, `[Controller::class, 'action']` controllers are named
  `Callback controller: App\Controller::action()` instead of `Unknown Symfony controller`.
- `interactor: auto` now resolves to `AdaptiveInteractor` and detects the agent at runtime.


## Installation

### Step 0: Install Elastic APM

Follow https://www.elastic.co/guide/en/apm/agent/php/current/intro.html.

### Step 1: Add dependency

#### With Symfony Flex (Recommended)

```bash
composer require mmft24/elastic-apm-symfony-bundle
```

The bundle will be automatically registered in `config/bundles.php`.

#### Without Symfony Flex

```bash
composer require mmft24/elastic-apm-symfony-bundle
```

Then manually register the bundle in `config/bundles.php`:

```php
<?php

return [
    // ...
    ElasticApmBundle\ElasticApmBundle::class => ['all' => true],
];
```

### Step 2: Configuring Elastic APM

You should review all the configuration items for the agent extension here, https://www.elastic.co/guide/en/apm/agent/php/current/configuration.html. These must be set either through environment variables or `php.ini`. These cannot be set during the request and so the bundle does not support setting them. 

### Step 3: Configure the bundle

Create `config/packages/elastic_apm.yaml` with the following options:

```yaml
elastic_apm:
    enabled: true                         # Defaults to true
    logging: false                        # If true, logs all interactions to a PSR-3 logger (default: false)
    interactor: ~                         # The interactor service that is used. Setting enabled=false will override this value 
    deprecations: true                    # If true, reports E_USER_DEPRECATED to Elastic APM (default: true)
    warnings: true                        # If true, reports E_WARNING/E_USER_WARNING to Elastic APM (default: true)
    report_silenced_errors: false         # If true, also reports warnings silenced with @ or excluded by error_reporting() (default: false); deprecations are always reported
    track_memory_usage: false             # If true, records peak memory usage
    memory_usage_label: memory_usage      # The name of the custom label to write memory usage to
    custom_labels:                        # Labels added to every transaction
        team: payments
    custom_context:                       # Custom context added to every transaction
        deployment: blue
    exceptions:
       enabled: true                      # If true, sends exceptions (default: true)
       unwrap_exceptions: false           # If true, also sends the previous/nested exceptions (default: false)
       capture_min_status_code: 500       # Min HTTP status an HttpException must carry to be reported; lower to 400 to capture 4xx signal (default: 500). Request exceptions (e.g. SuspiciousOperationException) count as 400; on the main request HttpKernel already converts them to BadRequestHttpException
       ignored_exceptions:                # List of exception classes to ignore (subclasses are ignored too)
          - An\Ignored\Exception
    http:
        enabled: true
        transaction_naming: route         # route, controller, uri or service (see below)
        transaction_naming_service: ~     # Transaction naming service (see below)
    commands: 
        enabled: true                     # If true, enhances CLI commands with options and arguments (default: true)
        explicitly_collect_exceptions: true # Turn this off if you are experiencing multiple reports of exceptions.
        sensitive_parameter_names: []     # Extra option/argument names whose values are redacted (see note below)
    messenger:
        enabled: false                    # Opt-in; requires symfony/messenger. Read the rollout notes below first
        transaction_type: message         # Type of the transaction started for each consumed message
        ignored_retryable_exceptions: []  # Exception classes/interfaces not reported while the message will be retried
    log_correlation:
        enabled: false                    # If true, adds trace.id/transaction.id/span.id to Monolog records (see below)
```

> **Note:** sensitive command options/arguments (those whose name contains `password`, `token`, `secret`,
> `api_key`, `dsn`, …) are automatically redacted before being sent to APM. Add your own names (matched as whole
> words, case-insensitive) with `commands.sensitive_parameter_names`.

When `commands` is enabled, the bundle also sets the result and outcome of the current transaction when a command
terminates, like the agent does for HTTP requests: the result is `CLI <exit code>` (e.g. `CLI 0`, `CLI 1`) and the
outcome is `success` for exit code 0 and `failure` otherwise. Exit codes changed by other `console.terminate`
listeners are respected.

## Warnings and deprecations

The warning and deprecation listeners install a PHP error handler at kernel boot and remove it at shutdown.

- Warnings silenced with `@` or excluded by `error_reporting()` are **not** reported unless
  `report_silenced_errors: true` is set.
- Deprecations are always reported, silenced or not, like Symfony's own ErrorHandler does: `trigger_deprecation()`
  and doctrine/deprecations emit every deprecation with `@`.
- Unregistering while another error handler sits on top of the bundle's leaves the bundle's handler in place as a
  pure pass-through (nothing is reported, the chain is kept) instead of popping the other handler.
- The handler always delegates to the previously registered error handler and returns its result unchanged (or
  `false` when there is none), so PHP's own error logging and `error_get_last()` behave as if the bundle were not
  installed.
- Identical errors (same level, message, file and line) are reported once per request / Messenger message.

To filter further, extend `WarningListener` or `DeprecationListener`, override
`shouldReport(int $type, string $message, string $file, int $line): bool` (call the parent to keep the level and
silencing checks) and swap the class on the bundle's service definition from a compiler pass, which keeps its
public visibility, `kernel.reset` tag and configuration:

```php
$container->getDefinition(\ElasticApmBundle\Listener\WarningListener::class)
    ->setClass(\App\Apm\FilteredWarningListener::class);
```

## Enhanced RUM instrumentation

This bundle does not integrate RUM (see https://www.elastic.co/guide/en/apm/server/current/overview.html) as there are multiple ways to install and configure the instrumentation.

## Transaction naming strategies

The bundle comes with three built-in transaction naming strategies:
- `route` — names the transaction after the matched route (default, recommended).
- `controller` — names the transaction after the resolved controller.
- `uri` — names the transaction after the request method and path. The query string is **dropped** to avoid leaking sensitive data (tokens, e-mail addresses, session ids) into transaction names. Note that dynamic path segments (e.g. `/users/123`) still produce distinct names, which can increase APM cardinality; prefer `route` when that matters.

However, the bundle also supports custom transaction naming strategies through the `service` configuration option. If you have selected the `service` configuration option, you must pass the name of your own transaction naming service as the `transaction_naming_service` configuration option.

The transaction naming service class must implement the `ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface` interface. For more information on creating your own services, see the Symfony documentation on [Creating/Configuring Services in the Container](http://symfony.com/doc/current/book/service_container.html#creating-configuring-services-in-the-container).

## Interactor services

The config key `elastic_apm.interactor` will accept a service ID to a service implementing `ElasticApmInteractorInterface`. 
This bundle comes with a few services that may be suitable for you. 

| Configuration value | Description |
| ------------------- | ----------- |
| `ElasticApmBundle\Interactor\AdaptiveInteractor` | This is the default interactor. It checks once, when the container instantiates it, whether the agent extension is loaded, and delegates to the real or the blackhole interactor accordingly. | 
| `ElasticApmBundle\Interactor\ElasticApmInteractor` | This interactor communicates with the Elastic APM agent. It is the one decorator that actually does some work. | 
| `ElasticApmBundle\Interactor\BlackholeInteractor` | This interactor silently drops any calls. | 
| `auto` | Same as `AdaptiveInteractor`: the agent is detected at runtime (extension loaded **and** agent enabled), not when the container is built, so a container warmed up with `ELASTIC_APM_ENABLED=false` (e.g. during a Docker build) still reports once the agent is enabled. | 

Note that if you set `elastic_apm.enabled: false` you will always use the `BlackholeInteractor` no matter what value 
used for `elastic_apm.interactor`.

## Monolog

The Elastic APM PHP extension does not directly support sending of log entries as anything other than errors. We recommend adding a new log handler and configuring the elasticsearch (or Elastica) client in your application configuration.

Example:

```yaml

# app/config/config.yml

monolog:
  handlers:
     errors_to_elasticsearch:
        type: buffer
        level: error
        handler: elasticsearch
     elasticsearch:
        type: service
        id: 'Monolog\Handler\ElasticsearchHandler'
```

## Symfony Messenger

Opt in (requires `symfony/messenger`) to trace messages across processes:

```yaml
elastic_apm:
    messenger: true
```

- **Producer side:** every message sent to a transport gets an `ElasticApmBundle\Messenger\ApmTracingStamp` carrying
  the W3C `traceparent`/`tracestate` headers of the active span (or of the current transaction when no span is
  active), so the consumer's transaction becomes its child. This hooks into Messenger's
  `SendMessageToTransportsEvent`, so it works for every bus without any bus configuration. The stamp survives both
  the default PHP serializer and Symfony's `Serializer` transport serializer.
- **Worker side:** `messenger:consume` no longer reports one endless transaction for the whole worker process. The
  transaction the agent started for the process is discarded as soon as the worker starts and each consumed message gets its own transaction,
  named `messenger:consume <transport> <MessageClass>`, that continues the producer's trace, including the sample
  rate carried in `tracestate`. It ends with result
  `MSG 2xx`/outcome `success` when handled, or `MSG 5xx`/outcome `failure` when it failed, with the configured
  custom labels/context and memory usage label attached. No transaction is carried over from one message to the
  next, including for skipped or rejected messages.
- **Errors:** the exceptions thrown by the handlers are reported, not Messenger's `HandlerFailedException` wrapper
  (`exceptions.unwrap_exceptions` still decides whether their previous exceptions are reported too). Failures you
  expect to recover on retry can be kept out of APM while the message will still be retried; the final failed
  attempt is always reported:

```yaml
elastic_apm:
    messenger:
        ignored_retryable_exceptions:
            - App\Messenger\ExpectedRetryExceptionInterface   # instanceof check: subclasses/implementations match
```

Buses whose `SendMessageMiddleware` has no event dispatcher (e.g. buses built by hand rather than by FrameworkBundle)
do not dispatch `SendMessageToTransportsEvent`. Add the `elastic_apm.messenger.middleware` middleware to them
instead; it is idempotent, so adding it to a FrameworkBundle bus as well is harmless:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - elastic_apm.messenger.middleware
```

Without the `elastic_apm` extension nothing is stamped and no transaction is started.

### Rolling it out safely

The stamp becomes part of every message's wire format, and a consumer that does not have the
`ElasticApmBundle\Messenger\ApmTracingStamp` class cannot decode a stamped message (the PHP serializer fails with
"class not found", Symfony's `Serializer` with "could not decode stamp"), so Messenger rejects it. This affects
pods still running an older release during a rolling deploy and other applications consuming the same transport.

- Enable the feature (or at least deploy a bundle version that has the stamp class) on **every consumer before any
  producer** starts stamping.
- Do **not roll back** to a version without the bundle's stamp class while stamped messages are still queued;
  drain the transports first, or disable `elastic_apm.messenger` and wait for the queues to empty before
  downgrading.

## Log ↔ trace correlation

Opt in to have every Monolog record carry the ids of the APM transaction it was written in, so Kibana can jump
from a log line to its trace (and back). Requires `monolog/monolog` ^3 and MonologBundle.

```yaml
elastic_apm:
    log_correlation:
        enabled: true                     # Default: false
        channels: []                      # Only these Monolog channels (default: all channels)
        handlers: []                      # Only these Monolog handlers (combinable with channels)
        field_names:                      # Keys written to the record's "extra" (defaults follow ECS)
            trace_id: trace.id
            transaction_id: transaction.id
            span_id: span.id
```

The `ElasticApmBundle\Monolog\TraceContextProcessor` (tagged `monolog.processor`) then adds to `$record->extra`:

| Field            | When                                    |
| ---------------- | --------------------------------------- |
| `trace.id`       | a transaction is being recorded         |
| `transaction.id` | a transaction is being recorded         |
| `span.id`        | additionally, a span is currently active |

Records written outside a transaction (or with the agent missing or disabled) are left untouched, and the processor
never throws. `channels` and `handlers` can be combined; one `monolog.processor` tag is added per entry.

Notes:
- This is unrelated to the `logging` option, which logs the bundle's own calls to the agent.
- Monolog 2 (array records) is not supported. Enabling `log_correlation` without Monolog 3 fails at container
  compile time with an explicit message; leaving it disabled works with any (or no) Monolog version.
- The fields land in `extra`; make sure your formatter/ingest pipeline maps them to the top-level ECS
  `trace.id`/`transaction.id`/`span.id` fields Kibana uses for correlation, or rename them via `field_names`.

## Troubleshooting

### Exceptions from commands are being recorded multiple times

PHP APM will automatically collect unhandled exceptions. The bundle will also install a listener for command exceptions. Our listener and the default behaviour can conflict which causes this behaviour. 

To fix this you can turn off `explicitly_collect_exceptions` under the `commands` configuration node.


## Development

### Running Unit Tests

This bundle includes comprehensive unit tests. You can run them in several ways:

#### Using Docker (Recommended)

The easiest way to run tests is using Docker. The image includes the `elastic_apm` PHP extension with the agent
enabled and `ELASTIC_APM_DISABLE_SEND=true`, so the tests drive the real agent without ever contacting an APM server:

```bash
# Build the Docker image (PHP 8.3 by default; use --build-arg PHP_VERSION=8.4 for 8.4)
docker build -t elastic-apm-symfony-bundle-test .

# Install dependencies (composer.lock is not committed) and run all tests
docker run --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest sh -c 'composer update && ./vendor/bin/phpunit'

# Lowest supported dependencies (Symfony 6.4)
docker run --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest sh -c 'composer update --prefer-lowest && ./vendor/bin/phpunit'

# Extension installed but agent disabled: exercises the fallback to the blackhole interactor
docker run --rm --volume $(pwd):/app -e ELASTIC_APM_ENABLED=false elastic-apm-symfony-bundle-test:latest ./vendor/bin/phpunit

# Run tests with coverage
docker run --rm --volume $(pwd):/app -e XDEBUG_MODE=coverage elastic-apm-symfony-bundle-test:latest ./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test file
docker run --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest ./vendor/bin/phpunit tests/Interactor/ElasticApmInteractorTest.php

# Interactive shell for debugging
docker run -ti --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest /bin/bash
```

#### Running Tests Locally

If you have PHP 8.3+ and the Elastic APM extension installed locally:

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run tests with coverage
./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test file
./vendor/bin/phpunit tests/Interactor/ElasticApmInteractorTest.php
```

**Note:** Some tests drive the real agent and need the `elastic_apm` extension loaded **and** the agent enabled
(`ELASTIC_APM_ENABLED` not `false`; set `ELASTIC_APM_DISABLE_SEND=true` to keep it from sending anything). Without
that, they are skipped: `ElasticApmInteractorTest`, `RealAgentIntegrationTest` and the `CommandListenerTest` cases that
mock `Elastic\Apm\TransactionInterface`. Everything else, including the kernel integration tests in
`tests/Integration` (which use a recording interactor), runs anywhere. See the
[official installation guide](https://www.elastic.co/guide/en/apm/agent/php/current/setup.html).

### Continuous Integration

This project uses GitHub Actions for continuous integration. The workflow automatically:

- **Runs PHPUnit tests** on PHP 8.3 and 8.4 against the real agent, failing if any test is skipped
- **Runs the lowest supported dependencies** (`composer update --prefer-lowest`, Symfony 6.4) on PHP 8.3
- **Runs with the agent disabled** (`ELASTIC_APM_ENABLED=false`) to cover the fallback interactors
- **Generates code coverage** reports (PHP 8.3 only)
- **Runs static analysis and code style checks**: ECS, PHPStan, Psalm and Rector (dry-run)
- **Executes on**:
  - Every push to `master`/`main` branches
  - Every pull request targeting `master`/`main`

The workflow uses Docker to ensure the `elastic_apm` PHP extension is available during testing. You can view the workflow status and results in the [Actions tab](https://github.com/mmft24/elastic-apm-symfony-bundle/actions) of the repository.

## Contributing

We welcome contributions! Here's how you can help:

### Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/YOUR-USERNAME/elastic-apm-symfony-bundle.git`
3. Create a feature branch: `git checkout -b feature/your-feature-name`
4. Make your changes
5. Run tests to ensure everything works (see [Running Unit Tests](#running-unit-tests))
6. Commit your changes with a clear message
7. Push to your fork: `git push origin feature/your-feature-name`
8. Open a Pull Request

### Contribution Guidelines

- **Code Style**: Follow the project's ECS configuration (`vendor/bin/ecs check`)
- **Tests**: Add tests for new features or bug fixes
- **Documentation**: Update documentation for any changed functionality
- **Compatibility**: Ensure compatibility with Symfony 6.4, 7.0, and 8.0 (as declared in `composer.json`) and PHP 8.3+
- **Commit Messages**: Write clear, descriptive commit messages

### Pull Request Process

1. Ensure all tests pass
2. Update the README.md if needed
3. Update the CHANGELOG.md with your changes
4. The PR will be reviewed by maintainers
5. Address any feedback or requested changes
6. Once approved, your PR will be merged

### Reporting Issues

If you find a bug or have a feature request:

1. Check if the issue already exists
2. If not, create a new issue with a clear title and description
3. Include steps to reproduce for bugs
4. Include your environment details (PHP version, Symfony version, etc.)

## Sponsor

If this bundle is useful to you, please consider sponsoring its development:

<iframe src="https://github.com/sponsors/mmft24/button" title="Sponsor mmft24" height="32" width="114" style="border: 0; border-radius: 6px;"></iframe>

[:heart: Sponsor mmft24 on GitHub](https://github.com/sponsors/mmft24)

## Credits

This bundle's design is based on the work done by:
- [ekino/EkinoNewRelicBundle](https://github.com/ekino/EkinoNewRelicBundle) - The foundational New Relic bundle that inspired this bundle's architecture.

Special thanks to all contributors who have helped improve this bundle.

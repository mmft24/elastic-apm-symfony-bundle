Elastic APM Symfony Bundle
=====================

[![Latest Version](https://img.shields.io/github/release/mmft24/elastic-apm-symfony-bundle.svg?style=flat-square)](https://github.com/mmft24/elastic-apm-symfony-bundle/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/mmft24/elastic-apm-symfony-bundle.svg?style=flat-square)](https://packagist.org/packages/mmft24/elastic-apm-symfony-bundle)
[![Tests](https://github.com/mmft24/elastic-apm-symfony-bundle/workflows/Tests/badge.svg)](https://github.com/mmft24/elastic-apm-symfony-bundle/actions)

This bundle integrates the Elastic APM PHP API into Symfony. For more information about Elastic APM, please visit https://www.elastic.co/apm. This bundle adds a lot more essentials. Here's a quick list:

1. **Better transaction naming strategy**: Your transaction traces can be named accurately by route names, the controller name, the request URI or you can decide on a custom naming strategy via a seamless interface that uses any naming convention you deem fit. While running console commands, it also sets the transaction name as the command name.

2. **Console Commands Enhancements**: While running console commands, its sets the options and arguments passed via the CLI as custom parameters to the transaction trace for easier debugging.

3. **Exception Listening**: It also captures all Symfony exceptions in web requests and console commands and sends them to Elastic APM.

4. **Interactor Service**: It provides access to most of the Elastic APM API via a Service class `ElasticApmInteractorInterface::class`. This can be injected it into any class, controller, service to communicate with APM. If the `adaptive` interactive is used then any APM calls will be ignored when the extension isn't loaded (for example in development environments).

    ```php
    $this->apm->addLabel('name', 'john');
    ```

5. **Logging Support**: In development, you are unlikely to have Elastic APM setup. There's a configuration to enable logging which outputs all actions to your Symfony log, hence emulating what it would actually do in production.


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
    logging: false                        # If true, logs all interactions to the Symfony log (default: false)
    interactor: ~                         # The interactor service that is used. Setting enabled=false will override this value 
    deprecations: true                    # If true, reports deprecations to Elastic APM (default: true)
    track_memory_usage: false             # If true, records peak memory usage
    memory_usage_label: memory_usage      # The name of the custom label to write memory usage to
    exceptions:
       enabled: true                      # If true, sends exceptions (default: true)
       should_unwrap_exceptions: false    # If true, will also sends the previous/nested exception (default: false)
       ignored_exceptions:                # List of exception classes to ignore
          - An\Ignored\Exception
    http:
        enabled: true
        transaction_naming: route         # route, controller or service (see below)
        transaction_naming_service: ~     # Transaction naming service (see below)
    commands: 
        enabled: true                     # If true, enhances CLI commands with options and arguments (default: true)
        explicitly_collect_exceptions: true # Turn this off if you are experiencing multiple reports of exceptions.
```

## Enhanced RUM instrumentation

This bundle does not integrate RUM (see https://www.elastic.co/guide/en/apm/server/current/overview.html) as there are a multiple of ways to install and configure the instrumentation.

## Transaction naming strategies

The bundle comes with three built-in transaction naming strategies:
- `route`
- `controller`
- `uri`
  
Naming the transaction after the route, controller or request URI respectively. However, the bundle supports custom transaction naming strategies through the `service` configuration option. If you have selected the `service` configuration option, you must pass the name of your own transaction naming service as the `transaction_naming_service` configuration option.

The transaction naming service class must implement the `ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface` interface. For more information on creating your own services, see the Symfony documentation on [Creating/Configuring Services in the Container](http://symfony.com/doc/current/book/service_container.html#creating-configuring-services-in-the-container).

## Interactor services

The config key`elastic_apm.interactor` will accept a service ID to a service implementing `ElasticApmInteractorInterface`. 
This bundle comes with a few services that may be suitable for you. 

| Configuration value | Description |
| ------------------- | ----------- |
| `ElasticApmBundle\Interactor\AdaptiveInteractor` | This is the default interactor. It will check once per request if the agent extension is installed or not. | 
| `ElasticApmBundle\Interactor\ElasticApmInteractor` | This interactor communicates with the Elastic APM agent. It is the one decorator that actually does some work. | 
| `ElasticApmBundle\Interactor\BlackholeInteractor` | This interactor silently drops any calls. | 
| `auto` | This value will check if the Elastic APM PHP extension is installed when you build your container. | 

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

## Troubleshooting

### Exceptions from commands are being recorded multiple times

PHP APM will automatically collect unhandled exceptions. The bundle will also install a listener for command exceptions. Our listener and the default behaviour can conflict which causes this behaviour. 

To fix this you can turn off `explicitly_collect_exceptions` under the `command` configuration node.


## Development

### Running Unit Tests

This bundle includes comprehensive unit tests. You can run them in several ways:

#### Using Docker (Recommended)

The easiest way to run tests is using Docker, which includes the required `elastic_apm` PHP extension:

```bash
# Build the Docker image
docker build -t elastic-apm-symfony-bundle-test .

# Run all tests
docker run --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest

# Run tests with coverage
docker run --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest ELASTIC_APM_ENABLED=true XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test file
docker run --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest ./vendor/bin/phpunit tests/Interactor/ElasticApmInteractorTest.php

# Interactive shell for debugging
docker run -ti --rm --volume $(pwd):/app elastic-apm-symfony-bundle-test:latest /bin/bash
```

#### Running Tests Locally

If you have PHP 8.2+ and the Elastic APM extension installed locally:

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

**Note:** Some tests require the `elastic_apm` PHP extension. Tests that require this extension will be automatically skipped if the extension is not available. See the [official installation guide](https://www.elastic.co/guide/en/apm/agent/php/current/setup.html).

### Continuous Integration

This project uses GitHub Actions for continuous integration. The workflow automatically:

- **Runs PHPUnit tests** on PHP 8.2 and 8.3
- **Generates code coverage** reports (PHP 8.3 only)
- **Checks code style** (if php-cs-fixer is configured)
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
5. Run tests to ensure everything works: `docker build -t elastic-apm-test . && docker run --rm elastic-apm-test`
6. Commit your changes with a clear message
7. Push to your fork: `git push origin feature/your-feature-name`
8. Open a Pull Request

### Contribution Guidelines

- **Code Style**: Follow PSR-12 coding standards
- **Tests**: Add tests for new features or bug fixes
- **Documentation**: Update documentation for any changed functionality
- **Compatibility**: Ensure compatibility with Symfony 6.0+ and PHP 8.2+
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

## Credits

This bundle is based largely on the work done by:
- [mmft24/elastic-apm-symfony-bundle](https://github.com/mmft24/elastic-apm-symfony-bundle) - The original Elastic APM Symfony Bundle
- [ekino/EkinoNewRelicBundle](https://github.com/ekino/EkinoNewRelicBundle) - The foundational work that inspired this bundle's architecture

Special thanks to all contributors who have helped improve this bundle.

# Elastic APM Symfony Bundle Documentation

## Overview

The Elastic APM Symfony Bundle integrates the [Elastic APM PHP API](https://www.elastic.co/apm) into Symfony applications, providing comprehensive application performance monitoring capabilities.

## Features

### 1. Flexible Transaction Naming

Transaction traces can be named using multiple strategies:
- **Route-based naming**: Uses Symfony route names
- **Controller-based naming**: Uses controller class and method names
- **URI-based naming**: Uses request URI patterns
- **Custom naming**: Implement your own strategy via `TransactionNamingStrategyInterface`

Console commands are automatically named after the command name.

### 2. Console Command Enhancement

When running console commands, the bundle automatically:
- Sets the transaction name to the command name
- Records CLI options and arguments as custom parameters for easier debugging

### 3. Exception Tracking

Automatically captures and reports:
- All Symfony exceptions in web requests
- Console command exceptions
- Configurable exception filtering and unwrapping

### 4. Interactor Service

Access Elastic APM API through dependency injection:

```php
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;

class MyService
{
    public function __construct(
        private ElasticApmInteractorInterface $apm
    ) {}

    public function myMethod(): void
    {
        $this->apm->addLabel('user', 'john');
        $this->apm->addCustomContext(['key' => 'value']);
    }
}
```

The `AdaptiveInteractor` (default) gracefully handles environments where the APM extension isn't loaded.

### 5. Development Support

Enable logging mode to output all APM interactions to your Symfony log when the APM extension isn't available in development.

## Installation

### Prerequisites

Install the Elastic APM PHP extension following the [official guide](https://www.elastic.co/guide/en/apm/agent/php/current/intro.html).

### Using Symfony Flex (Recommended)

```bash
composer require myschoolmanagement/elastic-apm-symfony-bundle
```

The bundle will be automatically registered thanks to Symfony Flex.

### Manual Installation (Without Flex)

1. Install the bundle:

```bash
composer require myschoolmanagement/elastic-apm-symfony-bundle
```

2. Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    ElasticApmBundle\ElasticApmBundle::class => ['all' => true],
];
```

## Configuration

### Elastic APM Agent Configuration

Configure the PHP agent extension via environment variables or `php.ini`. See the [official configuration reference](https://www.elastic.co/guide/en/apm/agent/php/current/configuration.html).

Example environment variables:
```bash
ELASTIC_APM_SERVICE_NAME=my-symfony-app
ELASTIC_APM_SERVER_URL=http://apm-server:8200
ELASTIC_APM_ENVIRONMENT=production
```

### Bundle Configuration

Create or update `config/packages/elastic_apm.yaml`:

```yaml
elastic_apm:
    # Enable/disable the bundle (default: true)
    enabled: true

    # Log all APM interactions to Symfony log (useful for development)
    logging: false

    # Interactor service (see Interactor Services section)
    interactor: ~

    # Track deprecations (default: true)
    deprecations:
        enabled: true

    # Track warnings (default: false)
    warnings:
        enabled: false

    # Memory usage tracking
    track_memory_usage: false
    memory_usage_label: memory_usage

    # Custom labels and context added to all transactions
    custom_labels:
        app_version: '1.0.0'
    custom_context:
        datacenter: 'us-east-1'

    # Exception tracking
    exceptions:
        enabled: true
        unwrap_exceptions: false  # Also report nested exceptions
        ignored_exceptions:
            - Symfony\Component\HttpKernel\Exception\NotFoundHttpException

    # HTTP request tracking
    http:
        enabled: true
        transaction_naming: route  # Options: route, controller, uri, service
        transaction_naming_service: ~  # Required when using 'service' option

    # Console command tracking
    commands:
        enabled: true
        # Disable if experiencing duplicate exception reports
        explicitly_collect_exceptions: true
```

## Transaction Naming Strategies

### Built-in Strategies

#### Route Naming (Recommended)
```yaml
elastic_apm:
    http:
        transaction_naming: route
```
Transaction: `GET /api/users/{id}` → `api_user_show`

#### Controller Naming
```yaml
elastic_apm:
    http:
        transaction_naming: controller
```
Transaction: `App\Controller\UserController::show`

#### URI Naming
```yaml
elastic_apm:
    http:
        transaction_naming: uri
```
Transaction: `/api/users/123` → `/api/users/{id}`

### Custom Strategy

1. Create a service implementing `TransactionNamingStrategyInterface`:

```php
namespace App\APM;

use ElasticApmBundle\TransactionNamingStrategy\TransactionNamingStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

class CustomNamingStrategy implements TransactionNamingStrategyInterface
{
    public function getTransactionName(Request $request): string
    {
        return sprintf(
            '%s %s %s',
            $request->getMethod(),
            $request->attributes->get('_route'),
            $request->headers->get('X-API-Version')
        );
    }
}
```

2. Configure the bundle to use your service:

```yaml
elastic_apm:
    http:
        transaction_naming: service
        transaction_naming_service: App\APM\CustomNamingStrategy
```

## Interactor Services

The `elastic_apm.interactor` configuration accepts a service ID implementing `ElasticApmInteractorInterface`.

### Available Interactors

| Service | Description |
|---------|-------------|
| `ElasticApmBundle\Interactor\AdaptiveInteractor` (default) | Checks once per request if the APM extension is loaded |
| `ElasticApmBundle\Interactor\ElasticApmInteractor` | Direct communication with the APM agent |
| `ElasticApmBundle\Interactor\BlackholeInteractor` | Silently ignores all APM calls |
| `auto` | Checks extension availability at container build time |

**Note**: When `enabled: false`, the `BlackholeInteractor` is always used regardless of configuration.

### Using the Interactor

The interactor is available as a service:

```php
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;

class OrderService
{
    public function __construct(
        private ElasticApmInteractorInterface $apm
    ) {}

    public function processOrder(Order $order): void
    {
        $this->apm->startTransaction('order.process');

        try {
            // Process order
            $this->apm->addLabel('order_id', $order->getId());
            $this->apm->addCustomContext([
                'total' => $order->getTotal(),
                'items' => count($order->getItems()),
            ]);

            $this->apm->endTransaction();
        } catch (\Exception $e) {
            $this->apm->captureException($e);
            throw $e;
        }
    }
}
```

## Logging Integration

The Elastic APM PHP extension doesn't directly support log entries. For comprehensive logging, configure a Monolog handler:

```yaml
monolog:
    handlers:
        errors_to_elasticsearch:
            type: buffer
            level: error
            handler: elasticsearch
        elasticsearch:
            type: service
            id: Monolog\Handler\ElasticsearchHandler
```

## Troubleshooting

### Duplicate Exception Reports

**Problem**: Exceptions from console commands are recorded multiple times.

**Cause**: Both the PHP APM extension and the bundle's listener capture exceptions.

**Solution**: Disable explicit exception collection:

```yaml
elastic_apm:
    commands:
        explicitly_collect_exceptions: false
```

### No Data in Development

**Problem**: No APM data appears when developing locally.

**Solution**: Enable logging mode to see what would be sent:

```yaml
elastic_apm:
    logging: true
```

Check your Symfony logs (e.g., `var/log/dev.log`) for APM interactions.

### Service Not Found Errors

**Problem**: Errors about `ElasticApmInteractorInterface` not found.

**Cause**: The bundle may not be properly registered or services not loaded.

**Solution**:
1. Clear cache: `php bin/console cache:clear`
2. Verify bundle is registered in `config/bundles.php`
3. Run `composer dump-autoload`

## Best Practices

1. **Use Route Naming**: Provides the best balance of readability and cardinality
2. **Filter Exceptions**: Exclude expected exceptions (404s, validation errors) to reduce noise
3. **Custom Labels**: Add consistent labels across your application for better filtering
4. **Memory Tracking**: Enable in production to identify memory leaks
5. **Adaptive Interactor**: Use in production to gracefully handle APM service outages

## Credits

This bundle is based on the work done by [ekino/EkinoNewRelicBundle](https://github.com/ekino/EkinoNewRelicBundle).

## Support

- **Issues**: [GitHub Issues](https://github.com/MySchoolManagement/elastic-apm-symfony-bundle/issues)
- **Elastic APM Documentation**: [https://www.elastic.co/apm](https://www.elastic.co/apm)
- **PHP Agent Guide**: [https://www.elastic.co/guide/en/apm/agent/php/current/intro.html](https://www.elastic.co/guide/en/apm/agent/php/current/intro.html)

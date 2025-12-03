ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

ENV ELASTIC_APM_VERSION=1.15.0
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    unzip \
    wget \
    autoconf \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install zip

RUN pecl channel-update pecl.php.net && \
    pecl install xdebug-3.4.0 && \
    docker-php-ext-enable xdebug

# Install Elastic APM PHP agent
RUN mkdir -p /usr/local/apm-agent-src && cd /usr/local/apm-agent-src \
        && curl -L https://github.com/elastic/apm-agent-php/releases/download/v${ELASTIC_APM_VERSION}/apm-agent-php_${ELASTIC_APM_VERSION}_$(dpkg --print-architecture).deb -o ./apm-agent-php_${ELASTIC_APM_VERSION}.deb \
        && dpkg -i apm-agent-php_${ELASTIC_APM_VERSION}.deb \
        && rm apm-agent-php_${ELASTIC_APM_VERSION}.deb \
        && rm -r /usr/local/apm-agent-src

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json ./

# Install PHP dependencies
RUN composer install --no-interaction --no-scripts --no-progress

# Copy the rest of the application
COPY . .

# Set environment variables for testing
ENV APP_ENV=test
ENV ELASTIC_APM_ENABLED=false
ENV ELASTIC_APM_LOG_LEVEL_STDERR=off

# Default command to run tests
CMD ["./vendor/bin/phpunit"]

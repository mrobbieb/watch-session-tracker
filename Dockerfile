FROM composer:latest AS composer

WORKDIR /app

RUN composer create-project symfony/skeleton:"6.4.*" /tmp/project \
    && cp -r /tmp/project/. /app

RUN composer require \
        symfony/framework-bundle \
        symfony/dotenv \
        predis/predis \
    && composer require --dev \
        phpunit/phpunit \
        mockery/mockery \
        symfony/test-pack

FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis && docker-php-ext-enable redis

WORKDIR /app

COPY --from=composer /app .

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
FROM php:8.2-cli

# Add the `unzip` package which PIE uses to extract .zip files
RUN export DEBIAN_FRONTEND="noninteractive"; \
    set -eux; \
    apt-get update; apt-get install -y --no-install-recommends zip libzip-dev libxml2-dev; \
    rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install zip simplexml

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /usr/src/myapp
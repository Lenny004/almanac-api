FROM docker.io/library/php:8.3-cli

# System libraries needed by the PHP extensions below
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-install intl pdo_pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Official Composer binary from the official image
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first so Docker can cache this layer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist

# Then copy the rest of the code
COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000
# Runs the migrations, then the PHP built-in web server (see docker/start.sh)
CMD ["sh", "docker/start.sh"]
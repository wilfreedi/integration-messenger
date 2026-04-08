FROM php:8.4-cli-alpine

RUN apk add --no-cache bash postgresql-client libpq-dev \
    && docker-php-ext-install pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

RUN composer dump-autoload --optimize

EXPOSE 8080

ENTRYPOINT ["sh", "/app/docker/php/entrypoint.sh"]

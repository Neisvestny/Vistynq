FROM php:8.5-fpm-alpine

RUN apk add --no-cache libpq-dev libsodium-dev \
    && docker-php-ext-install pdo_pgsql sodium

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /app

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
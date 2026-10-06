FROM php:8.5-fpm-alpine

# pdo_pgsql собирается за один слой.
# --timeout 30 — обрыв TCP-соединений, которые «залипают» без прогресса
# (иначе apk ждёт вечно и сборка виснет); ретраи переживают обрыв сети.
# Сборочные пакеты (.phpize-deps + libpq-dev) удаляются сразу после компиляции,
# поэтому toolchain не остаётся в образе. sodium уже есть в базовом образе.
RUN set -e; \
    for i in 1 2 3 4 5; do \
        if apk add --no-cache --timeout 30 --virtual .phpize-deps $PHPIZE_DEPS libpq-dev \
            && docker-php-ext-install pdo_pgsql \
            && apk del --no-network .phpize-deps \
            && apk add --no-cache --timeout 30 libpq; then \
            exit 0; \
        fi; \
        echo "backend build: attempt $i failed, retrying in 5s"; \
        sleep 5; \
    done; \
    exit 1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /app

EXPOSE 9000

# ENTRYPOINT обнуляет CMD, наследуемый от базового образа, — задаём явно,
# иначе `exec "$@"` в docker-entrypoint.sh завершает контейнер сразу после старта.
CMD ["php-fpm"]

ENTRYPOINT ["docker-entrypoint.sh"]

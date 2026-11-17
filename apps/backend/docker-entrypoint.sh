#!/bin/sh
set -e

if [ ! -f /app/vendor/autoload.php ]; then
  composer install --no-interaction --working-dir=/app
fi

mkdir -p /app/storage
chown -R www-data:www-data /app/storage

exec "$@"

#!/bin/sh
set -e

if [ ! -f /app/vendor/autoload.php ]; then
  composer install --no-interaction --working-dir=/app
fi

exec "$@"
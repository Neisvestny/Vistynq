#!/bin/sh
set -e

if [ ! -d /app/node_modules ]; then
  pnpm install --frozen-lockfile
fi

exec pnpm --filter frontend dev --host 0.0.0.0
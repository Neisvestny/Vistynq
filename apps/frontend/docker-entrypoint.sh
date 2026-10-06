#!/bin/sh
set -e

if [ ! -e /app/apps/frontend/node_modules/.bin/vite ]; then
  pnpm install --frozen-lockfile
fi

exec pnpm --filter frontend dev --host 0.0.0.0

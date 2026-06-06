#!/usr/bin/env bash
#
# Helm one-shot deploy — run from anywhere on the Cloudways server.
#
# Why this exists: the recurring "whole app shows 404" outage happens when the
# frontend build is skipped or fails, leaving api/public/app without an
# index.html (the Route::fallback then 404s every page). This script builds the
# SPA FIRST and aborts before swapping the live bundle if the build breaks, so a
# bad build can never take the app down. set -e makes any failure stop the run.
#
# Usage:  bash scripts/deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> [1/6] git pull origin main"
git pull origin main

echo "==> [2/6] composer install (api)"
cd "$ROOT/api"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [3/6] build frontend — must succeed before we touch the live bundle"
cd "$ROOT/web"
export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
# shellcheck disable=SC1091
[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh" && nvm use 20
npm ci
npm run build   # tsc --noEmit && vite build — aborts here on any type error
test -f "$ROOT/web/dist/index.html" || { echo "FATAL: build produced no dist/index.html — aborting, live app untouched"; exit 1; }

echo "==> [4/6] publish the freshly-built bundle"
rm -rf "$ROOT/api/public/app"
mkdir -p "$ROOT/api/public/app"
cp -r "$ROOT/web/dist/." "$ROOT/api/public/app/"

echo "==> [5/6] laravel deploy (migrate + caches + horizon restart)"
cd "$ROOT/api"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate || true

echo "==> [6/6] verify the bundle is in place"
test -f "$ROOT/api/public/app/index.html" || { echo "FATAL: index.html missing after copy — app would 404"; exit 1; }

echo "==> done. The SPA + /api are live."

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

echo "==> [1/6] git pull (skipped when this isn't a shell git checkout — e.g. Cloudways pulls via its Git panel)"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git pull origin main
else
  echo "    not a git working tree here — assuming the host already deployed the latest code; continuing with build + cache."
fi

echo "==> [2/6] composer install (api)"
cd "$ROOT/api"
# Self-heal a stale lock: if composer.lock predates a composer.json bump (e.g.
# the google-ads-php ^33 upgrade) `composer install` aborts and, under set -e,
# kills the whole deploy — silently shipping nothing. Fall back to updating that
# one package so a deploy is never blocked by an un-committed lock. Once the
# updated composer.lock is committed, the fast install path is used and this
# fallback never runs.
composer install --no-dev --optimize-autoloader --no-interaction || {
  echo "    composer install failed — lock out of date, reconciling googleads/google-ads-php…"
  composer update googleads/google-ads-php -W --no-dev --optimize-autoloader --no-interaction
}

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
# horizon:terminate tells Horizon to finish its current job and EXIT, on the assumption that
# something restarts it. For a long time nothing did — Horizon stayed dead after a deploy, queued
# jobs simply never ran, and the only reason anyone noticed was Bosco syncing by hand every
# morning. `horizon:status` said "inactive" while the app looked fine.
#
# The keep-alive cron (see docs/runbooks/server-cron.md) brings it back within a minute. We verify
# that here rather than trusting it: a deploy that silently leaves the queue dead is worse than a
# deploy that fails loudly.
php artisan horizon:terminate || true

echo "==> [6/6] verify the bundle is in place"
test -f "$ROOT/api/public/app/index.html" || { echo "FATAL: index.html missing after copy — app would 404"; exit 1; }

echo "==> [7/7] verify the queue is actually being consumed"
QUEUE_DRIVER="$(php artisan tinker --execute='echo config("queue.default");' 2>/dev/null | tail -1 | tr -d '[:space:]')"
if [ "$QUEUE_DRIVER" = "sync" ]; then
  echo "FATAL: QUEUE_CONNECTION=sync — every job runs INLINE in the web request."
  echo "       The daily sync's fast-dashboard split does nothing on this driver."
  echo "       Set QUEUE_CONNECTION=redis in .env and make sure Horizon runs."
  exit 1
fi

# Give the keep-alive cron a minute to bring Horizon back, then check. This is a WARNING, not a
# hard failure: the app serves fine without workers, it just stops syncing — and we'd rather ship
# and shout than block a deploy.
sleep 65
if ! php artisan horizon:status 2>/dev/null | grep -qi 'running\|active'; then
  echo "WARNING: Horizon is NOT running after the deploy. Queued jobs will not be processed."
  echo "         Check the keep-alive cron: crontab -l | grep horizon"
fi

echo "==> done. The SPA + /api are live."

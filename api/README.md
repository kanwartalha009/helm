# Helm — api (Laravel)

The Helm backend. Laravel 11 + PHP 8.3 + PostgreSQL 16 + Redis 7 + Horizon.

The architectural core is documented at `../docs/01-architecture/`. Read that before extending.

## Prerequisites

```bash
php -v       # need 8.3+
composer -v  # need 2.x
```

If you don't have them:

```bash
brew install php@8.3 composer
```

## First-time setup

### Option A — Docker for Postgres + Redis (recommended)

```bash
cd api
docker compose up -d              # starts Postgres 16 + Redis 7 in the background
composer install
cp .env.example .env
php artisan key:generate          # writes APP_KEY into .env
php artisan migrate               # creates the 8 Phase-1 tables
php artisan helm:health           # ← verify everything is wired
```

### Option B — Native (Homebrew)

```bash
brew install postgresql@16 redis
brew services start postgresql@16
brew services start redis
createdb helm
createuser helm --pwprompt        # set password to 'helm' to match .env.example

cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan helm:health
```

## Health check — what you should see

```
$ php artisan helm:health

  Helm health check

  ✓ Database connection  pgsql → 127.0.0.1/helm
  ✓ Postgres version     16.2
  ✓ Phase 1 tables       8 tables present
  ✓ Migrations           8 applied, 0 pending
  ✓ Redis                PONG
  ✓ APP_KEY              set (encrypts platform_credentials)
  ✓ App timezone         UTC

  ALL CHECKS PASSED  Helm is ready.
```

If anything is red, the line below the check tells you what to do.

## Running the API

```bash
php artisan serve          # http://localhost:8000 — handles HTTP
php artisan horizon        # in a second terminal — runs queue workers
php artisan schedule:work  # in a third terminal — fires daily/hourly sync jobs
```

The React app at `../web/` proxies `/api/*` to `http://localhost:8000` automatically.

## Testing the database connection without Helm

Quick sanity check that Postgres is reachable independently of Laravel:

```bash
psql -h 127.0.0.1 -p 5432 -U helm -d helm
# password: helm
> \dt                    # list tables (should show the 8 Phase-1 tables after migrate)
> SELECT version();      # confirms Postgres 16+
> SELECT count(*) FROM users;
> \q
```

Redis ping:

```bash
redis-cli ping           # should print "PONG"
```

## Testing the API

```bash
# 1. Confirm the server is up
curl -s http://localhost:8000/up
# expected: 200 with "Application up" body (Laravel's built-in /up health route)

# 2. Health check via API (Phase 1 will add a dedicated /api/health endpoint)

# 3. Create a master admin so you can sign in
php artisan tinker
>>> User::create([
...     'name' => 'Kanwar',
...     'email' => 'kanwartalha009@gmail.com',
...     'password' => Hash::make('your-password'),
...     'role' => 'master_admin',
... ]);
>>> exit

# 4. From the React app, sign in at /login with that email + password
```

## Useful diagnostics

```bash
# Show every artisan command
php artisan list

# Show migration state
php artisan migrate:status

# Wipe and re-run all migrations (DANGER — drops data)
php artisan migrate:fresh

# Open a REPL with Laravel booted
php artisan tinker

# Watch queue jobs in real-time
php artisan horizon
# then open http://localhost:8000/horizon  (master_admin only)

# Test a sync job manually for a brand
php artisan brand:sync meller --date=2026-05-15

# Tail logs
tail -f storage/logs/laravel.log
```

## Folder structure

Follows spec §9 verbatim. See `../docs/02-tech-stack/` for the locked stack.

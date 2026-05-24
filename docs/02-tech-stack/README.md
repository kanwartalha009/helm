# 02 — Tech stack

The stack is intentionally minimal. Every component is widely understood and well-documented. No exotic dependencies. **Do not introduce any framework or library not listed below without written approval.**

## Locked choices

| Component | Choice | Notes |
|-----------|--------|-------|
| Backend framework | Laravel 11 | PHP 8.3 required |
| Database | PostgreSQL 16 | Not MySQL. Required for JSONB and window functions. |
| Cache & queue | Redis 7 | Powers Laravel queues and rate-limit buckets. |
| Queue manager | Laravel Horizon | Visual queue monitor, concurrency per queue. |
| Auth | Laravel Sanctum | SPA token auth. No Passport, no JWT libraries. |
| Frontend framework | React 18 | No Next.js, no Remix. Pure SPA. |
| Frontend build | Vite 5 | Fast HMR, simple config. |
| Language (frontend) | TypeScript | Strict mode. |
| UI styling | Tailwind CSS 3 | With shadcn/ui component patterns (copy-paste, not a package). |
| Data fetching | TanStack Query v5 | Caching, retries, background refetch. |
| Tables | TanStack Table v8 | Sortable, filterable, virtualized. |
| Charts | Recharts | Drill-in trend charts. |
| State (client) | Zustand | Filter state, modal state. No Redux. |
| Forms | React Hook Form + Zod | Schema validation. |
| HTTP client | Axios | Interceptors for auth tokens. |
| Hosting | Hetzner CCX22 (€32/mo) | 4 vCPU, 16 GB RAM. Scales to ~500 brands. |
| Deployment | Laravel Forge | GitHub push to deploy. |
| Error tracking | Sentry | Both backend and frontend. |
| File storage | S3 (or DO Spaces) | Ticket attachments, profile images (Phase 3). |
| Version control | Git + GitHub | Monorepo with `/api` and `/web` folders. |

## Required PHP extensions

`pdo_pgsql`, `redis`, `bcmath`, `gd` or `imagick` (for future image processing), `curl`, `openssl`, `mbstring`, `xml`, `zip`.

## Required Composer packages — Phase 1

```
laravel/framework            ^11.0
laravel/sanctum              ^4.0
laravel/horizon              ^5.0
predis/predis                ^2.0   # or phpredis extension
guzzlehttp/guzzle            ^7.0
google-ads/google-ads-php           # Phase 1, Google integration
facebook/php-business-sdk           # Phase 1, Meta integration
sentry/sentry-laravel        ^4.0
```

## Required npm packages — Phase 1

```
react ^18, react-dom ^18
react-router-dom ^6
vite ^5, @vitejs/plugin-react
typescript ^5
tailwindcss ^3, postcss, autoprefixer
@tanstack/react-query ^5
@tanstack/react-table ^8
axios
zustand
recharts
react-hook-form
@hookform/resolvers
zod
date-fns
date-fns-tz
clsx
lucide-react
```

## Rationale notes

- **Postgres, not MySQL.** Helm leans on JSONB for `platform_connections.credentials` and `daily_metrics.metadata`, partial indexes, and window functions for delta calculations. MySQL would force workarounds.
- **No Next.js.** Server-side rendering buys nothing for an internal dashboard. Pure SPA keeps the build trivial and Nginx config one line.
- **shadcn/ui as a pattern, not a dependency.** Components are copy-pasted into `web/src/components/ui/` and owned by us. No package version to track, no breaking upgrades.
- **Zustand over Redux.** Filter and modal state only — no need for time-travel debugging or middleware ecosystems.
- **Horizon over plain `queue:work`.** The Phase 1 visual dashboard at `/horizon` is the difference between debugging a stuck Shopify sync in 30 seconds and an afternoon of `tail -f`.

# Phase 1 — schema

Seven tables. All defined as Laravel migrations.

## users

```
id              bigserial primary key
name            varchar(120)
email           varchar(190) unique not null
password        varchar(255) not null
role            varchar(30) not null default 'master_admin'
                  -- master_admin | manager | team_member | brand_user
status          varchar(20) not null default 'active'
                  -- active | invited | disabled
mfa_secret      varchar(255) null            -- encrypted
last_login_at   timestamptz null
last_login_ip   varchar(45) null
remember_token  varchar(100) null
created_at      timestamptz, updated_at timestamptz
```

## brands

```
id              bigserial primary key
name            varchar(120) not null
slug            varchar(140) unique not null
timezone        varchar(64) not null default 'UTC'    -- IANA tz
base_currency   char(3) not null default 'USD'
group_tag       varchar(60) null                       -- 'EU', 'spain', 'fashion'
status          varchar(20) not null default 'active'
                  -- active | paused | archived
created_at      timestamptz, updated_at timestamptz

index (status)
index (group_tag)
```

## platform_connections

```
id              bigserial primary key
brand_id        bigint not null references brands(id) on delete cascade
platform        varchar(30) not null    -- 'shopify' | 'meta' | 'google' | 'tiktok'
external_id     varchar(190) not null   -- shop domain, ad account id, etc.
display_name    varchar(190) null
credentials     jsonb not null          -- encrypted at application layer
metadata        jsonb null              -- currency, attribution window, etc.
status          varchar(20) not null default 'active'
                  -- active | paused | errored
last_sync_at    timestamptz null
last_error      text null
created_at      timestamptz, updated_at timestamptz

unique (platform, external_id)
index (brand_id, platform)
index (status)
```

## daily_metrics

The polymorphic table. One row per `(brand, platform, date)`. Shopify fills the commerce fields and leaves ad fields null; ad platforms do the inverse. **Never split this into per-platform tables.**

```
id                  bigserial primary key
brand_id            bigint not null references brands(id) on delete cascade
platform            varchar(30) not null
date                date not null            -- in brand's timezone

-- shopify fields (null for ad platforms)
revenue             numeric(14,2) null
revenue_net         numeric(14,2) null       -- after refunds
orders              integer null
refunds_amount      numeric(14,2) null
refunded_orders     integer null

-- ad platform fields (null for shopify)
spend               numeric(14,2) null
impressions         bigint null
clicks              integer null
conversions         integer null
conversion_value    numeric(14,2) null

-- currency
currency            char(3) not null
fx_rate_to_usd      numeric(14,6) not null   -- snapshotted at sync time, never recomputed

-- meta
metadata            jsonb null
is_complete         boolean not null default false
pulled_at           timestamptz not null

unique (brand_id, platform, date)
index (date, brand_id)
index (brand_id, date, platform)
```

Hot read query uses `(date, brand_id)`. Drill-in uses `(brand_id, date, platform)`.

## sync_logs

```
id                  bigserial primary key
brand_id            bigint references brands(id) on delete cascade
platform            varchar(30) not null
target_date         date not null
status              varchar(20) not null    -- queued | running | success | failed
started_at          timestamptz null
completed_at        timestamptz null
records_processed   integer null
error_message       text null
created_at          timestamptz

index (brand_id, target_date)
index (status, created_at)
```

Cleaned up Sundays at 02:00 — see [06-sync](../06-sync/README.md#schedules).

## currency_rates

```
id              bigserial primary key
date            date not null
base_currency   char(3) not null
target_currency char(3) not null
rate            numeric(14,6) not null
created_at      timestamptz

unique (date, base_currency, target_currency)
index (date)
```

Filled nightly by `FetchDailyCurrencyRatesJob` from exchangerate.host. The dashboard's USD column reads `revenue × fx_rate_to_usd` directly in SQL, not in PHP.

## platform_credentials

**Added 2026-05-16.** DB-backed storage for platform-level secrets (Meta System User token, Google MCC OAuth values, TikTok BC token) so the agency can rotate accounts without env-file edits. Each row stores ONE key/value pair encrypted with Laravel's `encrypted` cast. See [05-platforms / where credentials live](../05-platforms/README.md#where-credentials-live).

```
id              bigserial primary key
platform        varchar(30) not null    -- 'meta' | 'google' | 'tiktok'
key             varchar(60) not null    -- 'system_user_token', 'mcc_refresh_token', 'developer_token', etc.
value           text not null           -- encrypted via Laravel's `encrypted` cast
label           varchar(120) null       -- human-readable label, e.g. 'Production BM'
metadata        jsonb null              -- e.g. login_customer_id for Google
status          varchar(20) not null default 'active'
                  -- active | rotated | revoked
last_used_at    timestamptz null
expires_at      timestamptz null
created_by_user_id  bigint null references users(id)
created_at      timestamptz, updated_at timestamptz

unique (platform, key) where status = 'active'    -- partial index
index (platform, status)
```

**Rules:**
- Never log the decrypted value. The Settings UI reveals it only after re-entering the user's password.
- Every read goes through `PlatformCredentialService::get(platform, key)`. Direct model access is reserved for the credential controller.
- Rotation = insert new row with `status = 'active'`, flip prior row to `status = 'rotated'`. Old rows are kept so historical sync logs remain explicable.
- Every CRUD operation writes to `audit_logs`: `credential.created`, `credential.updated`, `credential.rotated`, `credential.revoked`.

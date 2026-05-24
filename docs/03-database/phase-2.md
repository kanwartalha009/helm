# Phase 2 — schema

Three new tables for deep analytics. The dashboard's blended ROAS row still reads from `daily_metrics`; these tables exist for the per-brand drill-in views.

## ad_performance_daily

One row per `(brand, platform, ad, date)`. Campaign and ad-set names are denormalized in to keep the read query cheap — if a campaign is renamed in Meta, the new name flows through on the next sync.

```
id                  bigserial primary key
brand_id            bigint not null references brands(id) on delete cascade
platform            varchar(30) not null
date                date not null

campaign_id         varchar(190) not null
campaign_name       varchar(255)
adset_id            varchar(190) null
adset_name          varchar(255) null
ad_id               varchar(190) null
ad_name             varchar(255) null

spend               numeric(14,2) not null
impressions         bigint not null default 0
clicks              integer not null default 0
purchases           integer not null default 0
purchase_value      numeric(14,2) not null default 0
ctr                 numeric(8,4) null
cpc                 numeric(10,4) null
frequency           numeric(8,2) null

currency            char(3) not null
pulled_at           timestamptz not null

unique (brand_id, platform, ad_id, date)
index (brand_id, date)
index (brand_id, campaign_id, date)
```

## product_performance_daily

Per-SKU revenue and refund tracking. Sourced from Shopify orders, refunded units attributed to the original order date.

```
id                  bigserial primary key
brand_id            bigint not null references brands(id) on delete cascade
date                date not null

product_id          varchar(190) not null
variant_id          varchar(190) null
sku                 varchar(190) null
product_title       varchar(500) null

units_sold          integer not null default 0
revenue             numeric(14,2) not null default 0
refund_units        integer not null default 0
refund_amount       numeric(14,2) not null default 0

currency            char(3) not null
pulled_at           timestamptz not null

unique (brand_id, product_id, variant_id, date)
index (brand_id, date)
```

## audit_findings

Store-audit results: page speed, broken Meta events, checkout funnel drop. Refreshed weekly.

```
id              bigserial primary key
brand_id        bigint not null references brands(id) on delete cascade
audit_type      varchar(60) not null
                  -- 'page_speed' | 'broken_event' | 'checkout_drop' | etc.
severity        varchar(20) not null   -- info | warn | critical
title           varchar(255) not null
description     text null
metric_value    numeric(14,4) null
threshold       numeric(14,4) null
detected_at     timestamptz not null
resolved_at     timestamptz null
resolved_by_id  bigint null references users(id)

index (brand_id, detected_at)
index (brand_id, audit_type)
```

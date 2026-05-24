# Phase 1.5 — schema

Three new tables added between Phase 1 and Phase 2. RBAC must land before deep analytics — retrofitting it later is multiple times harder.

## brand_user_access

Many-to-many between brands and users for `team_member` and `brand_user` roles. `master_admin` and `manager` bypass this table via the global scope in `Brand`.

```
id              bigserial primary key
brand_id        bigint not null references brands(id) on delete cascade
user_id         bigint not null references users(id) on delete cascade
access_level    varchar(20) not null default 'view'    -- view | edit
granted_by_id   bigint null references users(id)
granted_at      timestamptz not null default now()

unique (brand_id, user_id)
index (user_id)
```

## invitations

Token-based email invites. Token is **hashed** in the column — the raw token is only in the email body.

```
id              bigserial primary key
email           varchar(190) not null
role            varchar(30) not null
brand_ids       jsonb null                -- array of brand ids, null for full-access roles
token           varchar(190) not null unique   -- hashed
expires_at      timestamptz not null
invited_by_id   bigint not null references users(id)
accepted_at     timestamptz null
created_at      timestamptz

index (email)
```

## audit_logs

Append-only. Never deleted. Used for the per-action ledger and for security review.

```
id              bigserial primary key
actor_user_id   bigint null references users(id)
action          varchar(100) not null     -- 'user.invited', 'permission.granted', 'mfa.enabled', etc.
target_type     varchar(60) null
target_id       bigint null
metadata        jsonb null
ip              varchar(45) null
user_agent      varchar(500) null
created_at      timestamptz

index (actor_user_id, created_at)
index (target_type, target_id)
index (action, created_at)
```

See [08-rbac](../08-rbac/README.md) for which actions write to this table.

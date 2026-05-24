# Phase 3a — schema

Three tables for brand-side ticketing. External task tool sync (Phase 3b) reuses these tables; the `external_task_url` and `external_task_id` columns are added in Phase 3a so the schema doesn't need to migrate again.

## tickets

```
id                      bigserial primary key
brand_id                bigint not null references brands(id) on delete cascade
raised_by_user_id       bigint not null references users(id)
assigned_to_user_id     bigint null references users(id)
title                   varchar(255) not null
description             text not null
category                varchar(30) not null     -- bug | change | question | urgent
status                  varchar(30) not null default 'open'
                          -- open | triaged | in_progress | blocked | done | wont_do
priority                varchar(20) not null default 'med'
                          -- low | med | high | urgent
external_task_url       varchar(500) null         -- ClickUp/Linear/Asana link (Phase 3b)
external_task_id        varchar(190) null         -- for two-way sync (Phase 3b)
resolved_at             timestamptz null
created_at              timestamptz, updated_at timestamptz

index (brand_id, status)
index (assigned_to_user_id, status)
index (status, created_at)
```

## ticket_comments

`is_internal_note` is the gate: internal notes are never returned in API responses to `brand_user` role. Enforced in `TicketPolicy::viewInternalNotes` and the API resource.

```
id                  bigserial primary key
ticket_id           bigint not null references tickets(id) on delete cascade
user_id             bigint not null references users(id)
body                text not null
is_internal_note    boolean not null default false
created_at          timestamptz

index (ticket_id, created_at)
```

## ticket_attachments

S3-backed. The `path` column is the S3 key, not a public URL. URLs are signed at read time.

```
id                  bigserial primary key
ticket_id           bigint not null references tickets(id) on delete cascade
uploaded_by_user_id bigint not null references users(id)
filename            varchar(255) not null
path                varchar(500) not null     -- S3 key
mime_type           varchar(100)
size_bytes          bigint
created_at          timestamptz

index (ticket_id)
```

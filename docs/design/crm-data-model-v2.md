# CRM data model v2 — designed for 1,000+ Artists

**Status:** proposed · **Date:** 2026-10-27 · **Owner:** CRM/SDR
**Scope:** the shared Postgres used by the Laravel CRM and the n8n/Beatriz agent.
The LP MySQL database is out of scope.

This document proposes a clean-slate schema for the CRM/SDR database. There are
no live Artists today, so we can cut over without a data migration. The goal is a
model that stays correct and fast at **1,000+ Artists**, which is the unit VAIF
sells (see `CONTEXT.md`: *Artist* is the client, *Lead* is the tattoo customer).

---

## 1. Capacity assumptions

The current product note says each Artist receives **6–12 inbound Leads/day**.
That gives:

| Entity | Per Artist / year | At 1,000 Artists / year |
|---|---|---|
| Leads | ~2,200–4,400 | **2.2M–4.4M** |
| Projects (tattoo jobs) | ~2,200–4,400 | **2.2M–4.4M** |
| Events (audit/timeline) | ~5–10 per Lead | **11M–44M** |
| Bookings | ~300–800 | **0.3M–0.8M** |
| Outbox rows (if Notion kept) | ~1–1.5 per Lead | **3M–6M** |

Rough storage: 3–8 KB of heap+index per Lead across tables, ~0.3–0.8 KB per
Event. **Year one lands around 15–40 GB**, growing 15–40 GB/year. That is a
single well-indexed Postgres instance plus monthly partitions — not a sharding
problem yet. The design should avoid choices that make it one later.

Two assumptions to validate (see §13):

- 1,000+ means **1,000+ Artists**, not 1,000 Leads.
- Traffic is concentrated in Brazilian working hours (10:00–19:00 BRT), so the
  write peak is roughly 10–20× the average, not 1,000×.

---

## 2. What is wrong with the current model

The current schema lives in three overlapping files
(`20240601000000_schema.sql`, `001_idempotent_schema.sql`,
`000_coolify_full_migration.sql`) and the deployed shape is whatever was applied
by hand. The `relation "tattoos" does not exist` incident is the symptom; these
are the underlying causes.

### 2.1 Correctness bugs that will not survive 1,000 tenants

1. **Chat state is keyed by `chat_id` alone.**
   `message_buffer.chat_id` and `chat_control.chat_id` are global primary keys
   (`001_idempotent_schema.sql:175`, `:973`). The same phone can talk to two
   Artists; their buffers and ownership would collide. This is a data-leak bug,
   not a scaling one.
2. **No unique Lead per `(artist_id, phone)`.**
   `CONTEXT.md` defines "one Lead per person per Artist", but `leads` has no
   unique constraint (`001_idempotent_schema.sql:52–89`). Duplicates are one
   retry away.
3. **One row = person + job.**
   A returning Lead, or a Lead buying two tattoos in one conversation, cannot be
   modelled. The current model overwrites or duplicates. `returning_customers`
   is a workaround for the missing relationship.
4. **Double-booking is not enforced by the database.**
   `calendar` has no exclusion constraint (`001_idempotent_schema.sql:148`).
   Correctness depends on every writer remembering the advisory lock inside
   `book_slot`. A new writer — the Laravel reschedule path already exists — can
   violate it.
5. **Two app columns are missing from every Postgres migration.**
   `pricing.region` and `artists.pricing_extras` are written by
   `SettingsController` but are not created by any file in
   `packages/crm/supabase/migrations`; they exist in the SQLite fixture and, at
   best, as an undocumented manual `ALTER` in production. Saving prices or
   adding a custom size/location on a clean deploy will fail the same way
   `tattoos` did.
6. **`SECURITY DEFINER` functions do not pin `search_path`.**
   Every RPC in `001_idempotent_schema.sql` is `SECURITY DEFINER` without
   `SET search_path`. Combined with any default `CREATE` grant on `public`
   (PostgreSQL 14 and earlier; verify on the deployed version), that is a
   privilege-escalation footgun.

### 2.2 Scale problems

7. **`events` is an unbounded UUID table.**
   No partitioning, no retention, random UUIDv4 PKs, JSONB payloads
   (`001_idempotent_schema.sql:160–169`). At 20M rows/year this becomes the
   largest object and the slowest to vacuum.
8. **`notion_sync_outbox` is a global queue with no archival.**
   Done rows accumulate forever; `idx_outbox_pending` only helps the hot path.
9. **Chat buffer writes are row upserts in Postgres.**
   Every inbound WhatsApp message upserts `message_buffer`; at 1,000 Artists that
   is tens of millions of writes/year for ephemeral state that a TTL store should
   own.
10. **Analytics scans domain rows.**
    `AnalyticsController` loads all Leads for the Artist and aggregates in PHP.
    At 4k Leads/artist/year this is fine; at 5 years it is not. There is no
    daily rollup.
11. **Config and secrets share a row.**
    `artists.notion_token` sits next to display name and working hours
    (`001_idempotent_schema.sql:49`). Rotating a token touches the tenant row;
    a tenant dump leaks credentials.

### 2.3 Operational problems

12. **No migration history.** Three files create/alter the same tables; some
    columns exist only because someone ran an `ALTER` by hand. There is no way to
    answer "what is in production?" from the repo.
13. **No environment parity.** Tests run on a SQLite fixture that has already
    diverged twice (`tattoos`, `pricing.region`). A green test suite is not
    evidence the schema works.
14. **No retention policy.** Leads, events and outbox have no archival or LGPD
    deletion story.

---

## 3. Decisions at a glance

| Decision | Choice | Why |
|---|---|---|
| Tenancy | **Shared schema + `artist_id` on every tenant row** | 1,000 tenants × ~15 tables would mean 15k relations under schema-per-tenant; migrations and catalog bloat are worse than tenant filters. |
| Tenant integrity | **Composite foreign keys** `(artist_id, parent_id) → parent(artist_id, id)` | A child row cannot point at another tenant's parent even if application code is wrong. |
| Lead vs job | **Split `leads` (person) and `projects` (tattoo job)** | Returning customers and multi-tattoo deals are first-class. The board stays Lead-centric by showing each Lead's active Project. |
| Calendar | **One `calendar_entries` table** (booking + block) with a GiST exclusion constraint | Overlap protection in the database, including blocks vs bookings. |
| Events | **Monthly range partitions, `bigint` identity, append-only** | Retention = `DROP PARTITION`; inserts stay local. |
| PKs | **UUIDv7 for domain rows, `bigint` for append-only logs** | Time-ordered UUIDs avoid random-index bloat while keeping opaque URLs. |
| Money | **integer cents, BRL, no floats** | Exact sums for revenue/deposits. |
| Time | **`timestamptz` in UTC; Artist timezone only for availability** | No ambiguous local timestamps in storage. |
| Status | **Lookup table `pipeline_stages`**, existing PT keys preserved | Avoids free-text statuses without rewriting the agent's prompt vocabulary. |
| Agent API | **RPCs take explicit `artist_id`**; no session GUC | PgBouncer transaction pooling makes `set_config(..., true)` session state unsafe. |
| Migrations | **SQL-first, versioned, one baseline**; Laravel migrations only for app-owned tables | The schema must be reproducible without Laravel. |
| DB access | **Least-privilege roles** (`crm_app`, `agent_rw`, `analytics_ro`, `migrator`) | RPC-only for the agent; no broad `public` write grants. |
| RLS | **Defense-in-depth only, not the tenancy boundary** | See §7; revisit if Artists ever connect to Postgres directly. |

The corresponding ADR is `docs/adr/0002-crm-schema-v2.md`.

---

## 4. Domain model

```
artists 1─1 artist_settings
    │
    ├─1─N artist_integrations        (notion / waha / telegram credentials)
    ├─1─N price_book                (versioned price matrix)
    ├─1─N promotions ─1─N promotion_prices
    ├─1─N leads                     (person; unique per phone)
    │      ├─1─N lead_channels      (whatsapp/instagram ids)
    │      ├─1─N projects           (tattoo job)
    │      │      ├─1─N quotes
    │      │      ├─1─N payments
    │      │      └─1─N calendar_entries (bookings)
    │      └─1─1 chat_sessions      (per artist+chat ownership/buffer)
    ├─1─N calendar_entries          (blocks have no project/lead)
    ├─1─N events                    (append-only timeline)
    ├─1─N integration_outbox
    └─1─N returning_customers       (imported or confirmed)
```

**Lead** = person per Artist (`unique (artist_id, phone_e164)`).
**Project** = one tattoo job for a Lead. A Lead can have many Projects over time;
a Project can have many Quotes, Payments and (normally one) Booking.
**Active Project** = the Project the Artist should act on. The CRM board can
derive it as "open Project with the most recent `updated_at`".

This deliberately reintroduces a person/job split, but unlike the deleted
`Tattoo` model it is a real domain distinction, not a phantom table:

- Ana gets a back piece in January and an arm piece in June → **one Lead, two
  Projects**. The returning badge comes from the prior completed Booking, not a
  duplicate Lead.
- Carlos buys the "Leve 2" promotion for himself and a friend → **one Lead, two
  Projects**, each with its own placement, quote and deposit.
- A Lead who only asks for a price → **one Lead, one Project** in
  `orcamento_enviado`.

The Laravel board remains a list of Leads, one card per Lead (its active
Project), so the artist-facing UX does not change.

---

## 5. Schema

The DDL below is the core. Extensions: `btree_gist` (for the calendar exclusion
constraint); `gen_random_uuid()` is built into Postgres 13+. UUIDv7 values are
generated by the application (`Symfony\Component\Uid\UuidV7`, already available
through Laravel 10's `symfony/uid` dependency). Note: `generated always as
identity` on a **partitioned** table requires PostgreSQL 17; on PG15/16 (the
likely Supabase/Coolify versions) use a sequence default as shown for `events`.

### 5.1 Tenants and configuration

```sql
create table artists (
  id          uuid primary key,                 -- UUIDv7 from the app
  slug        text not null unique,             -- wa_session_slug / URLs
  name        text not null,
  status      text not null default 'stub'
              check (status in ('stub','onboarding','live','suspended','offboarded')),
  timezone    text not null default 'America/Sao_Paulo',
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create table artist_settings (
  artist_id       uuid primary key references artists(id) on delete cascade,
  display_name    text,
  specialties     text[] not null default '{}',
  excluded_styles text[] not null default '{}',   -- replaces nao_faco
  floor_pct       numeric(5,2) check (floor_pct between 0 and 100),
  deposit_type    text not null default 'percent'
                  check (deposit_type in ('percent','fixed')),
  deposit_value   integer check (deposit_value >= 0),
  pix_key         text,
  instagram_handle text,
  whatsapp_number text,
  working_hours   jsonb not null default '{}'::jsonb,
  ai_active_hours jsonb not null default '{}'::jsonb,
  currency        text not null default 'BRL' check (char_length(currency) = 3),
  updated_at      timestamptz not null default now(),
  check (jsonb_typeof(working_hours) = 'object'),
  check (jsonb_typeof(ai_active_hours) = 'object')
);

-- One row per external integration. Secrets are encrypted at the application
-- layer (Laravel encrypter) or with pgcrypto; never returned by the API.
create table artist_integrations (
  artist_id    uuid not null references artists(id) on delete cascade,
  kind         text not null check (kind in ('notion','waha','telegram')),
  credentials  jsonb not null default '{}'::jsonb,
  external_id  text,
  status       text not null default 'disconnected'
               check (status in ('disconnected','connected','error')),
  updated_at   timestamptz not null default now(),
  primary key (artist_id, kind)
);
```

`users` stays Laravel-owned (`role`, `artist_id`). Add
`foreign key (artist_id) references artists(id)` so a login cannot outlive its
tenant.

`on delete cascade` is for the LGPD hard-delete runbook only. Offboarding an
Artist must be a status change (`status = 'offboarded'`), which keeps history
and stops the agent; it must not delete rows.

### 5.2 Leads and channels

```sql
create table leads (
  id            uuid primary key,
  artist_id     uuid not null references artists(id) on delete cascade,
  name          text,
  phone_e164    text not null,
  phone_raw     text,                            -- as the Lead typed it
  source        text,
  first_seen_at timestamptz not null default now(),
  last_message_at timestamptz,
  archived_at   timestamptz,
  anonymized_at timestamptz,                     -- LGPD erasure marker
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),
  unique (artist_id, phone_e164),
  unique (artist_id, id)                         -- target for composite FKs
);

create index leads_artist_recent
  on leads (artist_id, last_message_at desc nulls last)
  where archived_at is null;

create table lead_channels (
  artist_id   uuid not null,
  lead_id     uuid not null,
  channel     text not null check (channel in ('whatsapp','instagram','telegram','web')),
  external_id text not null,                     -- WAHA chat id, IG thread, ...
  created_at  timestamptz not null default now(),
  primary key (artist_id, channel, external_id),
  foreign key (artist_id, lead_id) references leads (artist_id, id) on delete cascade
);

-- Composite PK covers "find Lead by chat id"; this covers "list a Lead's ids".
create index lead_channels_lead on lead_channels (artist_id, lead_id);
```

`chat_sessions` replaces the global `message_buffer` / `chat_control` keys:

```sql
create table chat_sessions (
  artist_id       uuid not null references artists(id) on delete cascade,
  chat_id         text not null,
  lead_id         uuid not null,
  owner           text not null default 'agent' check (owner in ('agent','artist')),
  taken_at        timestamptz,
  returned_at     timestamptz,
  last_inbound_at timestamptz,
  last_outbound_at timestamptz,
  typing_state    text,
  updated_at      timestamptz not null default now(),
  primary key (artist_id, chat_id),
  foreign key (artist_id, lead_id) references leads (artist_id, id) on delete cascade
);

create index chat_sessions_lead on chat_sessions (artist_id, lead_id);
```

The **debounce buffer itself should move to Redis** (`artist:chat:pending`, TTL
a few minutes). If Redis is not available, keep a `chat_buffers` table with a
composite key and an `expires_at` cleanup job. Postgres is the wrong place for
high-frequency ephemeral state.

### 5.3 Projects and pipeline stages

```sql
create table pipeline_stages (
  key         text primary key,
  label_pt    text not null,
  sort_order  integer not null,
  is_open     boolean not null default true,
  is_won      boolean not null default false,
  is_lost     boolean not null default false,
  check (not (is_won and is_lost))
);

insert into pipeline_stages (key, label_pt, sort_order, is_open, is_won, is_lost) values
  ('novo',                'Novo',                 10, true,  false, false),
  ('qualificando',        'Qualificando',         20, true,  false, false),
  ('orcamento_enviado',   'Orçamento enviado',     30, true,  false, false),
  ('aguardando_deposito', 'Aguardando sinal',     40, true,  false, false),
  ('agendado',            'Agendado',             50, true,  true,  false),
  ('aguardando_artista',  'Aguardando artista',   60, true,  false, false),
  ('fechado',             'Fechado',              70, false, true,  false),
  ('perdido',             'Perdido',              80, false, false, true),
  ('bloqueado',           'Bloqueado',            90, true,  false, false);

create table projects (
  id              uuid primary key,
  artist_id       uuid not null,
  lead_id         uuid not null,
  stage_key       text not null default 'novo' references pipeline_stages(key),
  placement       text,
  body_zone       text,
  style           text,
  first_tattoo    boolean,
  meaning         text,
  tattoo_type     text check (tattoo_type in ('new','cover_up')),
  reference_pics  text[] not null default '{}',
  handoff_reason  text,
  accepted_quote_id uuid,                        -- set when the Lead accepts
  closed_at       timestamptz,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  foreign key (artist_id, lead_id) references leads (artist_id, id) on delete cascade,
  unique (artist_id, id)
);

create index projects_board
  on projects (artist_id, stage_key, updated_at desc);
create index projects_lead
  on projects (lead_id, created_at desc);
create index projects_open
  on projects (artist_id, updated_at desc)
  where closed_at is null;
```

The existing PT status keys are kept on purpose: they are the vocabulary in the
agent's prompts and n8n branches. The lookup table removes free-text drift; the
UI labels still come from it (or `config/crm.php`).

### 5.4 Prices, quotes, payments

`pricing` becomes a **versioned price book** so a price change does not rewrite
history for quotes already sent:

```sql
create table price_book (
  id                  uuid primary key,
  artist_id           uuid not null references artists(id) on delete cascade,
  placement           text not null,
  body_zone           text not null,
  base_price_cents    integer not null check (base_price_cents >= 0),
  session_duration_min integer not null check (session_duration_min > 0),
  buffer_min          integer not null default 30 check (buffer_min >= 0),
  effective_from      timestamptz not null default now(),
  effective_to        timestamptz,
  created_at          timestamptz not null default now(),
  check (effective_to is null or effective_to > effective_from)
);

-- Exactly one current row per matrix cell.
create unique index price_book_current
  on price_book (artist_id, placement, body_zone)
  where effective_to is null;

create index price_book_lookup
  on price_book (artist_id, placement, body_zone, effective_from desc);
```

A quote is a snapshot, whether it came from the table or a promotion:

```sql
create table quotes (
  id                  uuid primary key,
  artist_id           uuid not null,
  project_id          uuid not null,
  kind                text not null default 'standard'
                      check (kind in ('standard','promotion')),
  promotion_id        uuid,                      -- FK to promotions when kind='promotion'
  base_price_cents    integer not null check (base_price_cents >= 0),
  offered_price_cents integer not null check (offered_price_cents >= 0),
  discount_percent    numeric(5,2),
  second_cap_cents    integer,                   -- "Leve 2" second tattoo cap
  valid_until         timestamptz,
  status              text not null default 'open'
                      check (status in ('open','accepted','declined','lapsed','void')),
  created_by          text not null default 'agent'
                      check (created_by in ('agent','artist','staff')),
  created_at          timestamptz not null default now(),
  foreign key (artist_id, project_id) references projects (artist_id, id) on delete cascade
);

create unique index quotes_one_open_per_project
  on quotes (project_id) where status = 'open';
create index quotes_artist_status on quotes (artist_id, status);
create index quotes_project_history
  on quotes (artist_id, project_id, created_at desc);
```

`projects.accepted_quote_id` is set when the Lead accepts; the deposit/payment
rows reference the Project, not the Lead, because a person can have two jobs.
Add `quotes.promotion_id references promotions(id)` in the real migration; it is
omitted here only because `promotions` is described in §5.7. `accepted_quote_id`
needs a deferred FK created after both tables exist (project ↔ quote cycle).

```sql
create table payments (
  id             uuid primary key,
  artist_id      uuid not null,
  project_id     uuid not null,
  kind           text not null default 'deposit'
                 check (kind in ('deposit','balance','refund')),
  amount_cents   integer not null check (amount_cents > 0),
  currency       text not null default 'BRL' check (char_length(currency) = 3),
  status         text not null default 'requested'
                 check (status in ('requested','confirmed','failed','refunded')),
  method         text,                            -- pix, cash, card, ...
  external_ref   text,
  requested_at   timestamptz not null default now(),
  confirmed_at   timestamptz,
  foreign key (artist_id, project_id) references projects (artist_id, id) on delete cascade
);

create unique index payments_one_open_deposit
  on payments (project_id)
  where kind = 'deposit' and status in ('requested','confirmed');
create index payments_project_history
  on payments (artist_id, project_id, requested_at desc);
```

If payments stay manual for now, the table still gives an audit trail; the
Project's `deposit_status` becomes a derived value, not a source of truth.

### 5.5 Calendar: bookings and blocks in one table

A single table is what makes database-enforced overlap protection possible
across bookings *and* blocks.

```sql
-- Run as a superuser/migrator role; on Supabase this is available by default.
create extension if not exists btree_gist;

create table calendar_entries (
  id          uuid primary key,
  artist_id   uuid not null references artists(id) on delete cascade,
  kind        text not null check (kind in ('booking','block')),
  project_id  uuid,
  lead_id     uuid,
  start_at    timestamptz not null,
  end_at      timestamptz not null,
  active      boolean not null default true,      -- cancelled bookings stay for audit
  reason      text,
  created_by  text not null default 'agent'
              check (created_by in ('agent','artist','staff')),
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  check (end_at > start_at),
  check (kind = 'block' or (project_id is not null and lead_id is not null)),
  foreign key (artist_id, project_id) references projects (artist_id, id),
  foreign key (artist_id, lead_id) references leads (artist_id, id)
);

alter table calendar_entries
  add constraint calendar_no_overlap
  exclude using gist (
    artist_id with =,
    tstzrange(start_at, end_at, '[)') with &&
  ) where (active);

create index calendar_artist_range
  on calendar_entries (artist_id, start_at)
  where active;
create index calendar_project
  on calendar_entries (artist_id, project_id)
  where active and project_id is not null;
create index calendar_lead
  on calendar_entries (artist_id, lead_id)
  where active and lead_id is not null;
```

Consequences:

- `book_slot` no longer needs an advisory lock to be correct; the exclusion
  constraint is the last line of defence. Keeping the lock reduces retries.
- An overlapping insert fails with SQLSTATE `23P01` (`exclusion_violation`); the
  RPC should catch it and return a typed "slot taken" result rather than a raw
  database error to n8n.
- A cancelled booking is `active = false`, not a deleted row, so history stays.
- `check_availability` reads only `calendar_entries` with `active`.

### 5.6 Events: append-only, partitioned

```sql
-- Sequence default instead of IDENTITY: identity columns on partitioned
-- tables only work on PostgreSQL 17+.
create sequence events_id_seq;

create table events (
  id          bigint not null default nextval('events_id_seq'),
  artist_id   uuid not null references artists(id) on delete cascade,
  lead_id     uuid,
  project_id  uuid,
  actor       text not null check (actor in ('agent','artist','staff','system')),
  type        text not null,
  payload     jsonb not null default '{}'::jsonb,
  created_at  timestamptz not null default now(),
  primary key (id, created_at)
) partition by range (created_at);

create index events_artist_time on events (artist_id, created_at desc);
create index events_project_time on events (project_id, created_at desc)
  where project_id is not null;
```

Monthly partitions (created ahead of time by a scheduled task):

```sql
-- Bounds are explicit UTC so the partition does not shift with the session TZ.
create table events_2026_11 partition of events
  for values from ('2026-11-01 00:00:00+00')
               to ('2026-12-01 00:00:00+00');
```

Retention is `alter table events detach partition ...` followed by
`drop table`, or export to Parquet/S3 first. Keep 24 months hot by default.

`events` has no FK to `leads`/`projects` because partitions cannot be a stable FK
target across detach/drop. The `artist_id` FK to `artists` is safe (tenants are
never dropped, only offboarded/anonymized). Application code writes the IDs.

### 5.7 Promotions and returning customers

Keep the existing promotion shape (it models the mechanic well), with small
fixes:

```sql
-- promotion_types (key, name, description, config_spec, active)
-- promotions (id, artist_id, type_key, name, multiplier, audience,
--             conditions, quote_valid_days, status, activated_at, paused_at,
--             ended_at, created_at, updated_at)
--   one active promotion per artist (partial unique index) — already correct
-- promotion_prices (promotion_id, placement, body_zone, base_price_cents,
--                   offer_price_cents) — snapshot at activation
```

Promotion quotes now live in `quotes` (`kind='promotion'`,
`promotion_id = ...`); `second_cap_cents` replaces `second_cap_price`. Drop the
separate `promotion_quotes` table.

`returning_customers` stays as a cheap lookup/import registry, keyed by
`(artist_id, phone_e164)`, and is also written when a Booking is completed:

```sql
create table returning_customers (
  artist_id    uuid not null references artists(id) on delete cascade,
  phone_e164   text not null,
  name         text,
  source       text not null default 'imported'
               check (source in ('imported','booking_confirmed')),
  confirmed_at timestamptz,
  created_at   timestamptz not null default now(),
  primary key (artist_id, phone_e164)
);
```

For correctness, the source of truth is "has a completed booking with this
Artist"; the table is a cache/import surface, not a second truth.

### 5.8 Outbox

```sql
create table integration_outbox (
  id             bigint generated always as identity primary key,  -- not partitioned, PG10+
  artist_id      uuid not null references artists(id) on delete cascade,
  integration    text not null check (integration in ('notion','telegram','other')),
  entity_type    text not null,
  entity_id      uuid not null,
  payload        jsonb not null,
  dedupe_key     text,
  unique (artist_id, dedupe_key),
  status         text not null default 'pending'
                 check (status in ('pending','processing','done','failed')),
  attempts       integer not null default 0,
  next_attempt_at timestamptz not null default now(),
  last_error     text,
  locked_by      text,
  locked_at      timestamptz,
  created_at     timestamptz not null default now(),
  processed_at   timestamptz
);

create index outbox_ready
  on integration_outbox (next_attempt_at)
  where status in ('pending','failed');
```

Purge `done` rows older than 30 days (or partition by month if outbox volume
grows). `dedupe_key` makes retries idempotent.

### 5.9 Analytics rollup (optional, add when needed)

Scanning `events` for every dashboard load will not survive 20M rows. Add a
daily rollup when the first slow query appears:

```sql
create table artist_daily_metrics (
  artist_id      uuid not null references artists(id) on delete cascade,
  day            date not null,
  leads_new      integer not null default 0,
  projects_new   integer not null default 0,
  quotes_sent    integer not null default 0,
  bookings       integer not null default 0,
  revenue_cents  bigint not null default 0,
  primary key (artist_id, day)
);
```

Populated by a nightly job from `leads`, `quotes`, `calendar_entries` and
`payments`. The CRM reads this for charts; live pipeline counts still come from
indexed domain queries.

---

## 6. Index plan

| Table | Index | Purpose |
|---|---|---|
| `leads` | `unique (artist_id, phone_e164)` | one Lead per person per Artist |
| `leads` | `(artist_id, last_message_at desc) where archived_at is null` | inbox / recent |
| `lead_channels` | `pk (artist_id, channel, external_id)` | WhatsApp lookup without collision |
| `lead_channels` | `(artist_id, lead_id)` | channels for one Lead |
| `projects` | `(artist_id, stage_key, updated_at desc)` | kanban columns |
| `projects` | `(artist_id, updated_at desc) where closed_at is null` | active project per Lead |
| `projects` | `(lead_id, created_at desc)` | Lead detail history |
| `quotes` | `unique (project_id) where status='open'` | one live quote |
| `quotes` | `(artist_id, project_id, created_at desc)` | quote history on a Project |
| `payments` | `(artist_id, project_id, requested_at desc)` | payment history on a Project |
| `calendar_entries` | GiST exclusion `(artist_id, tstzrange)` where active | no double-booking |
| `calendar_entries` | `(artist_id, start_at) where active` | agenda/week |
| `calendar_entries` | `(artist_id, project_id) where active and project_id not null` | booking per Project |
| `calendar_entries` | `(artist_id, lead_id) where active and lead_id not null` | bookings on a Lead |
| `events` | `(artist_id, created_at desc)` per partition | dashboard/audit |
| `events` | `(project_id, created_at desc)` where project_id not null | Lead timeline |
| `integration_outbox` | `(next_attempt_at) where status in ('pending','failed')` | worker claim |
| `integration_outbox` | `unique (artist_id, dedupe_key)` | idempotent enqueue per tenant |
| `returning_customers` | `pk (artist_id, phone_e164)` | returning lookup |

Guidelines:

- Every FK gets an index on the child side (Postgres does not create one). The
  composite FK to `leads (artist_id, id)` needs one column list per child table
  (`lead_id`), not one index per referenced column.
- Partial indexes for hot subsets (open projects, pending outbox) keep the
  indexes small.
- Do not add GIN indexes on `payload`/`conditions` until a query needs them.
- Re-check `pg_stat_user_indexes` after load tests; unused indexes are write
  cost at 1,000 tenants.

---

## 7. Tenancy, roles, and RLS

**Tenant boundary:** `artist_id` on every tenant-owned row plus composite FKs.
This is enforced by the database even if the app has a bug:

```sql
-- A project can only reference a Lead of the same Artist.
foreign key (artist_id, lead_id) references leads (artist_id, id)
```

**Roles:**

| Role | Grants |
|---|---|
| `migrator` | owns the schema, runs DDL (deploy job only) |
| `crm_app` | `select/insert/update` on domain tables; no DDL |
| `agent_rw` | `execute` on the RPCs only; no direct table grants |
| `analytics_ro` | `select` on domain tables + rollups |

Also `revoke create on schema public from public;` and pin `search_path` on every
`SECURITY DEFINER` function (`set search_path = public, pg_temp`).

**RLS:** keep the existing policies only if an Artist will ever query Postgres
directly (e.g. a future Supabase client). Today only Laravel and n8n connect, so
RLS is not the tenancy boundary and the `app.artist_id` session GUC is unsafe
under transaction pooling. Recommended: remove RLS from the critical path, rely
on roles + composite FKs, and re-introduce RLS with a per-transaction
`set_config` if direct access becomes a requirement. This supersedes the RLS
clause of ADR-0001; see ADR-0002.

---

## 8. Agent API (RPC surface)

The agent should not write tables directly. Keep a small, explicit RPC layer.
Every function takes `p_artist_id` and validates that every referenced row belongs
to that Artist (the composite FKs make this cheap).

| RPC | Replaces | Notes |
|---|---|---|
| `resolve_artist(p_slug text)` | `resolve_artist_from_session` | returns artist + settings |
| `upsert_lead(p_artist_id, p_phone, p_name, p_source)` | inline `INSERT ... ON CONFLICT` | normalizes phone; returns Lead |
| `resolve_chat_session(p_artist_id, p_chat_id, p_lead_id)` | `take_chat_control` / `chat_owner` | creates/returns `chat_sessions` row |
| `create_project(p_artist_id, p_lead_id, p_placement, ...)` | none (new) | one job per call |
| `lookup_price(p_artist_id, p_placement, p_body_zone)` | `lookup_price` | reads current `price_book` |
| `write_quote(p_artist_id, p_project_id, ...)` | `write_quote` | inserts into `quotes`, moves stage |
| `write_promotion_quote(p_artist_id, p_project_id, p_promotion_id, ...)` | `write_promotion_quote` | inserts `kind='promotion'` |
| `request_deposit(p_artist_id, p_project_id, p_amount)` | `request_deposit` | inserts `payments` + stage |
| `confirm_deposit(p_artist_id, p_project_id)` | `confirm_deposit` | confirms payment, adds returning customer |
| `check_availability(p_artist_id, p_from, p_to, p_duration)` | same | reads `calendar_entries` |
| `book_slot(p_artist_id, p_project_id, p_start, p_duration)` | `book_slot_checked` | inserts booking; exclusion constraint is the guard |
| `reschedule_booking(p_artist_id, p_booking_id, p_start)` | app-local logic | one RPC used by app and agent |
| `block_time` / `unblock_time` | same | `calendar_entries` kind='block' |
| `take_chat_control` / `return_chat_control` | same | `chat_sessions.owner` |
| `activate/pause/end_promotion` | same | unchanged semantics |
| `enqueue_outbox` / `claim_outbox` / `complete_outbox` / `fail_outbox` | notion outbox | generic integration queue |

Rules:

- Functions are `SECURITY DEFINER` with `search_path = public, pg_temp`, owned by
  `migrator`; `agent_rw` gets `EXECUTE` only.
- Every mutation writes an `events` row in the same transaction.
- Return the changed row (`SETOF ...`) so n8n can use the result without a
  second read.
- No function reads or writes another Artist's rows: validate
  `artist_id = p_artist_id` on every parent lookup.

Because we are free to change n8n workflows, this is a clean replacement, not a
compatibility layer.

---

## 9. Naming and data conventions

- Schema identifiers are **English snake_case**: `leads`, `projects`,
  `calendar_entries`, `price_book`.
- Enum/status **values stay Portuguese** (`novo`, `aguardando_deposito`,
  `perdido`) because they are the agent's internal vocabulary. Labels live in
  `pipeline_stages.label_pt`.
- Money columns end in `_cents` and are `integer`/`bigint`, never floats.
- Timestamps end in `_at` and are `timestamptz`; dates are `date`.
- Phones are `phone_e164`; keep `phone_raw` for display/audit.
- No `data`, `info`, `meta`, `value` column names. Prefer domain names.
- Every mutable table has `created_at` and `updated_at`; append-only tables have
  `created_at` only.
- Use `text` + `check`/lookup table, not PostgreSQL `enum`, so adding a stage is
  a row insert, not a migration.

---

## 10. Migration to v2 (no live data)

Because there are no live Artists, this is a cutover, not a data migration.
Still do it in verifiable phases.

**Phase 0 — make the current schema knowable (1 day).**
- `pg_dump --schema-only` the deployed DB; commit it as
  `packages/crm/db/baseline/0001_production.sql`.
- Add a CI job that applies the baseline + v2 migrations to a throwaway
  Postgres container and runs SQL contract tests. No more SQLite-only proof.
- Fix the two missing columns in the current app if it must stay up before v2:
  `alter table pricing add column if not exists region text;`
  `alter table artists add column if not exists pricing_extras jsonb;`

**Phase 1 — build and load-test v2 (2–3 days).**
- Write `packages/crm/db/migrations/0001_schema_v2.sql` (the DDL above) and
  `0002_functions_v2.sql` (RPCs).
- Generate a synthetic dataset: 1,000 Artists, 3M Leads, 3M Projects, 15M Events,
  500k Calendar entries. Use `generate_series` + a deterministic
  `random()` seed so benchmarks are repeatable.
- Benchmarks: board query p95 < 50 ms, agenda p95 < 30 ms, `check_availability`
  p95 < 100 ms, event insert > 2k rows/s.
- Verify the exclusion constraint under concurrent `book_slot` calls (two
  sessions, same slot).

**Phase 2 — port the write paths (2–3 days).**
- Rewrite the ~20 n8n Postgres nodes to the new RPC names/tables; update
  `packages/flows/definitions/*.json` and the migration workflow.
- Port the Laravel models/controllers (Lead, Project, Quote, CalendarEntry,
  Payment) and the Blade views.
- SQL contract tests for every RPC: happy path, cross-tenant denial, invalid
  transition, double booking, quote expiry.

**Phase 3 — cutover (1 day).**
- Deploy schema, functions, n8n workflows and app together.
- Re-seed demo data.
- Run the end-to-end checklist: login, board, lead detail, book, reschedule,
  cancel, deposit, promotion quote, analytics, onboarding.

**Phase 4 — operations.**
- Partition maintenance job (create next 2 months, detach > 24 months).
- Outbox purge job.
- Daily metrics rollup (when analytics needs it).
- PITR backup + quarterly restore test.

Rollback before cutover is trivial: the v1 tables are untouched until Phase 3.
After cutover, keep a `pg_dump` of the pre-cutover database.

---

## 11. Capacity and operations at 1,000 Artists

| Concern | Recommendation |
|---|---|
| Instance | Start with 4 vCPU / 16 GB / 200 GB SSD; scale vertically first. A single instance handles 15–40 GB/year comfortably. |
| Connections | PgBouncer in transaction mode for n8n; Laravel uses a small pool (10–20). Keep `max_connections` ≤ 200 and pool in front. |
| Autovacuum | Lower `autovacuum_vacuum_scale_factor` for `events` partitions (0.02) and `leads` (0.05); monitor `pg_stat_user_tables`. |
| Partitioning | `events` monthly. If `leads` crosses ~50M rows, partition by `created_at` range; do not hash-partition by Artist yet. |
| Analytics | Daily rollups; optional read replica if charts compete with writes. |
| Backups | PITR (WAL-G/pgBackRest) + daily logical dump; test restore quarterly. |
| Monitoring | `pg_stat_statements`, slow query log (>200 ms), outbox lag, partition count, disk. |
| Secrets | Integration credentials encrypted in `artist_integrations`; never in `artists`. |
| LGPD | Retention policy per table; `anonymized_at` on Leads; export/delete runbook; do not log phone numbers in app logs. |
| Notion | 1,000 per-Artist Notion tokens is an operational burden. Decide whether Notion remains a product surface or the CRM replaces it. |

A note on WAHA: 1,000 Artists also means 1,000 WhatsApp sessions. That is an
infrastructure concern (one WAHA per N sessions, or the official Cloud API), not
a schema one, but it should be sized in the same plan.

---

## 12. What we deliberately reject

- **Schema-per-tenant.** 1,000 × ~15 tables, 1,000 migration targets, catalog
  bloat, cross-tenant analytics pain. Shared schema + `artist_id` is the right
  default at this size.
- **Hash-partitioning by `artist_id` now.** It helps tenant isolation but makes
  global queries, uniqueness and migrations harder. Time-range partitions solve
  the actual problem (retention).
- **Keeping one wide `leads` table.** It cannot represent returning customers or
  multi-tattoo deals, and it mixes integration state with domain state.
- **RLS as the only tenancy boundary.** Session GUCs under transaction pooling
  are fragile; composite FKs + roles are stronger for this topology.
- **JSONB for core fields.** Placement, price, stage and dates are queried and
  constrained; they belong in columns. JSONB is for payloads and flexible
  conditions only.
- **A compatibility view named `tattoos`.** It hides the model and repeats the
  mistake that caused the outage.

---

## 13. Decisions needed before implementation

1. **Accept the Lead/Project split?** It changes the app's models and the board
   query, but it is the only way to model returning customers and multi-tattoo
   deals correctly. Recommendation: yes.
2. **Is Notion still a product surface at 1,000 Artists?** If not, drop
   `artist_integrations.kind='notion'` and the outbox consumer, saving ~3–6M
   rows/year.
3. **Payments:** manual confirmation only, or a real provider (Pix/card)? The
   `payments` table works for both; a provider adds `external_ref` and webhooks.
4. **Retention:** how long do we keep Leads/Events? LGPD and the Artist contract
   should set it (suggest 24 months hot events, 5 years Lead records, then
   anonymize).
5. **Direct DB access:** will Artists ever query Postgres/Supabase directly? If
   yes, RLS moves back into scope.
6. **Deployment target:** self-hosted Coolify Postgres or managed Supabase? This
   determines PITR, extensions (`btree_gist`, `pg_partman`) and connection
   pooling.
7. **One Postgres or separate analytics DB?** At year one, one instance + read
   replica is enough; revisit at ~50M events.

---

## Appendix A — `created_at` triggers

Use one shared trigger function for `updated_at`:

```sql
create or replace function touch_updated_at() returns trigger as $$
begin
  new.updated_at = now();
  return new;
end;
$$ language plpgsql;

create trigger trg_artists_touch before update on artists
  for each row execute function touch_updated_at();
-- repeat for artist_settings, leads, projects, quotes, calendar_entries
```

## Appendix B — partition helper

```sql
create or replace function create_monthly_partition(p_month date)
returns void as $$
declare
  v_start timestamptz := date_trunc('month', p_month)::date::timestamp at time zone 'UTC';
  v_end   timestamptz := v_start + interval '1 month';
  v_name  text := 'events_' || to_char(v_start, 'YYYY_MM');
begin
  if to_regclass('public.' || v_name) is null then
    execute format(
      'create table %I partition of events for values from (%L) to (%L)',
      v_name, v_start, v_end
    );
  end if;
end;
$$ language plpgsql;
```

Call it for the current and next two months from a scheduled task (n8n cron or
Laravel scheduler).

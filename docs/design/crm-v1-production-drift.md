# Production schema drift — 2026-10-28

The deployed schema does not match any single file in
`packages/crm/supabase/migrations`. This document records the confirmed
differences, why they exist, and what they change about the v2 design.

**Evidence:** `packages/crm/db/baseline/0001_production.sql` — `pg_dump
--schema-only` captured on 2026-10-28 from the Coolify Postgres.

**Environment:** PostgreSQL **17.7**, `pg_dump` 17.7, owner `postgres`, no
extensions installed, no triggers, no explicit `GRANT`/`REVOKE`. 20 tables, 31
functions, 14 indexes, 5 RLS policies. The dump contains **no data** (zero
`COPY` blocks), so row counts and duplicate rates must be measured separately.

---

## P0 — breaks the CRM as deployed

### 1. `pricing.region` does not exist

`SettingsController::storePricingBulk` writes `region` on every price save.
`Pricing::$fillable` includes it. The column exists in the SQLite fixture and in
no Postgres migration, and it is **not in production**. Saving the price table
fails with `SQLSTATE 42703` (`column "region" ... does not exist`).

### 2. `artists.pricing_extras` does not exist

Same failure mode when an Artist adds a custom size or location
(`SettingsController` lines 190–218). Also absent from production.

Both are app features that look implemented and tested locally, and fail only
against the real database — the same pattern as the `tattoos` outage.

**Hotfix:** both `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` statements were
appended to `001_idempotent_schema.sql` and the generated
`migration-beatriz.json` was rebuilt. They take effect when the DB Migration
workflow runs (or the SQL is applied manually). They do not fix anything until
then.

---

## P1 — tenant integrity and security

### 3. Four core tables have no foreign key to `artists`

| Table | Column | FK in production |
|---|---|---|
| `leads` | `artist_id` | **missing** |
| `pricing` | `artist_id` | **missing** |
| `calendar` | `artist_id` | **missing** |
| `events` | `artist_id` | **missing** |

`leads`, `pricing`, `calendar` and `events` can point at a non-existent Artist,
and nothing prevents a writer from mixing tenants. Only `calendar.lead_id`,
`events.lead_id` and the newer promotion/chat tables have FKs.

### 4. No unique Lead per `(artist_id, telefone)`

Production has a **non-unique** `idx_leads_artist_phone (artist_id, telefone)`.
The domain rule "one Lead per person per Artist" is unenforced. Existing
duplicates are unknown because the dump is schema-only:

```sql
select count(*) - count(distinct (artist_id, telefone))
from leads;
```

### 5. Chat state is keyed by `chat_id` alone

`message_buffer (chat_id primary key)` and `chat_control (chat_id primary key)`.
The n8n Code nodes build `chat_id` as `session:phone`, and `wa_session_slug` is
unique per Artist, so there is **no current collision** — but the convention is
not enforced by the schema. A new writer that uses a raw phone number collides
across tenants.

### 6. RLS is partial and can error

Enabled on 5 tables only: `chat_control`, `promotion_prices`, `promotion_quotes`,
`promotions`, `returning_customers`. `artists`, `leads`, `calendar`, `events` and
`pricing` have **no RLS**. Policies call
`current_setting('app.artist_id')` without `missing_ok`, so a session that never
ran `set_artist_context` raises `42704` instead of returning zero rows.

### 7. All 31 functions are `SECURITY DEFINER` without `search_path`

Confirmed for every function in the dump. There are no roles and no `GRANT`
statements; everything is owned by `postgres`. The app and n8n almost certainly
connect as a superuser — confirm the connection strings, but the dump gives no
evidence of a limited role.

---

## P2 — scale and retention

### 8. `chat_memory` is the biggest unmanaged table

Created at runtime by the n8n Postgres Chat Memory node:

```
chat_memory (id integer pk, session_id varchar(255), message jsonb)
```

One row per message. **No index on `session_id`**, no retention, no `artist_id`,
no migration. At 1,000 Artists × ~20 messages per Lead it reaches 40–80M
rows/year, and every agent turn filters it by an unindexed column. This is the
first table that will hurt, and it was not in the v2 draft.

### 9. `events` has no scale story

`uuid` PK, no partitioning, one index `(lead_id, created_at)`. There is no
`(artist_id, created_at)` index for the dashboard, and JSONB payloads are never
archived. 11–44M rows/year.

### 10. `notion_sync_outbox` grows forever

Done rows are never deleted; only `idx_outbox_pending` exists. v2 removes Notion
and the table entirely, so this is a deletion, not a redesign.

### 11. `calendar` relies on application discipline

No exclusion constraint, no `calendar.artist_id` FK, and `type` still defaults
to `'available'` even though availability is now derived from working hours.
Only `book_slot`'s advisory lock prevents double-booking.

### 12. `updated_at` is not maintained everywhere

There are no triggers. RPCs update `leads.updated_at`; `artists.updated_at` is
never touched by any function, so it is effectively a creation timestamp.

---

## P3 — repo/production divergence

### 13. Columns that exist only in production

`artists`: `notion_database_id`, `notion_api_key`, `telegram_bot_token`,
`studio_address`, `updated_at`. None are created by any file in
`packages/crm/supabase/migrations`. They are leftovers from an earlier shape.

### 14. Functions that exist only in the repo

`20240602000000_crud_functions.sql` defines 8 functions that were **never
applied** and are not called by the app or the workflows: `create_lead`,
`load_lead`, `update_qualification`, `mark_pipeline_state`, `mark_handoff`,
`close_won`, `close_lost`, `current_artist_id`. It is dead code; v2 should delete
the file rather than port it.

### 15. Production-only runtime tables

`chat_memory` (n8n-owned) has no migration. The Laravel-owned tables (`users`,
`notes`, `failed_jobs`, `migrations`, `personal_access_tokens`,
`password_reset_tokens`) are created by `packages/crm/app/database/migrations`,
which is correct — but that means the domain schema has two owners and no single
history.

### 16. There is no schema history

The `migrations` table only tracks Laravel migrations. Domain tables were
created by hand-pasted SQL, then "reconciled" by `CREATE TABLE IF NOT EXISTS` and
`ALTER TABLE ... ADD COLUMN IF NOT EXISTS` — which add **columns** but never add
**constraints**. That is the direct cause of item 3.

---

## Why this happened

`001_idempotent_schema.sql` starts with `CREATE TABLE IF NOT EXISTS`. On a
database where the table already existed with an older shape, the `CREATE` is
skipped, and the reconcile block only adds missing columns. Foreign keys, unique
constraints and indexes declared inside the skipped `CREATE` are never applied.
Each "safe to re-run" pass therefore made the drift more permanent.

## Corrections to the v2 design

1. **Chat-key claim softened.** §2.1 no longer calls it a live data leak: the
   `session:phone` convention works today. Composite keys are still required
   because the convention is not enforced.
2. **`returning_customers.source`** is `'deposit_confirmed'`, not
   `'booking_confirmed'`. The table is written by `confirm_deposit`, matching
   production.
3. **PostgreSQL 17.7 confirmed.** Identity columns on partitioned tables are
   supported; v2 still uses a sequence default for portability.
4. **`btree_gist` is absent** and must be created in Phase 1.
5. **`chat_memory` is now in scope** (§5.10): index `(session_id, id)` and a
   30-day purge, or replace it with a partitioned `messages` table.
6. **No roles exist.** The role split in §7 is greenfield, not a tightening of
   existing grants.

## Phase 0 status

- [x] Capture the deployed schema (`packages/crm/db/baseline/`)
- [x] Record the server version (17.7) and extension availability (`btree_gist`
      missing)
- [x] Write down the drift (this document)
- [ ] Add a Postgres CI job that applies the baseline + v2 migrations and runs
      SQL contract tests
- [ ] Choose the backup mechanism (WAL-G/pgBackRest vs nightly dump)
- [ ] Measure duplicate Leads and existing row counts in production

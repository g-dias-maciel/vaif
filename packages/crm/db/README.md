# CRM database operations

## `baseline/`

The schema-only dump of the **deployed v1 database** (2026-10-28,
PostgreSQL 17.7). It is evidence, not a desired state. See
[`docs/design/crm-v1-production-drift.md`](../../../docs/design/crm-v1-production-drift.md).

## `rebuild-v1.sh`

Drops `schema public` and rebuilds the database from
`packages/crm/supabase/migrations/001_idempotent_schema.sql` plus
`20261022000000_offers_and_chat_control.sql`, then lets the app container's
entrypoint run Laravel migrations.

Use it when the database is disposable (no live Artists) and you want a clean v1
that matches the repo — it restores the foreign keys and the two settings
columns that the hand-patched production database lost.

```bash
# on the Coolify host
scp -r packages/crm/db root@host:/tmp/vaif-rebuild
scp packages/crm/supabase/migrations/001_idempotent_schema.sql \
    packages/crm/supabase/migrations/20261022000000_offers_and_chat_control.sql \
    root@host:/tmp/vaif-rebuild/
cd /tmp/vaif-rebuild
CONFIRM=yes ./rebuild-v1.sh
```

What it does:

1. Takes a **full** `pg_dump` (data included) to
   `/tmp/crm-pre-rebuild-<timestamp>.dump`.
2. Saves the `users` table so existing logins survive (`RESTORE_USERS=yes`).
3. Stops the CRM container.
4. Drops and recreates `public`, applies the two SQL files.
5. Starts the container; the entrypoint runs `php artisan migrate` for
   `users`, `notes`, `promotion_quotes`, etc.
6. Restores `users`, then verifies artist/lead counts, tenant FKs and the two
   settings columns.

If any step fails it restores the backup automatically (`AUTO_ROLLBACK=yes`).

### Env knobs

| Var | Default | Meaning |
|---|---|---|
| `CONFIRM` | — | Must be `yes`; otherwise the script refuses to run |
| `PG_CONTAINER` | first `postgres` container | Postgres container name |
| `APP_CONTAINER` | first container matching `crm` | Laravel container name |
| `DB_USER` / `DB_NAME` | `postgres` / `postgres` | Connection target |
| `RESTORE_USERS` | `yes` | Keep existing CRM logins |
| `AUTO_ROLLBACK` | `yes` | Restore the dump on failure |

### What it does not fix

- No `unique (artist_id, telefone)` on `leads`
- Partial RLS
- Unindexed `chat_memory`
- `events` without partitions

Those are v2 changes, not v1 hotfixes. See
[`docs/design/crm-data-model-v2.md`](../../../docs/design/crm-data-model-v2.md).

### Restoring the backup manually

```bash
docker exec -i <pg> psql -U postgres -d postgres -c 'drop schema public cascade; create schema public;'
docker exec -i <pg> pg_restore -U postgres -d postgres --no-owner < /tmp/crm-pre-rebuild-*.dump
docker start <app>
```

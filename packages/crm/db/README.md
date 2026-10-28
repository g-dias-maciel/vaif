# CRM database operations

## `baseline/`

The schema-only dump of the **deployed v1 database** (2026-10-28,
PostgreSQL 17.7). It is evidence, not a desired state. See
[`docs/design/crm-v1-production-drift.md`](../../../docs/design/crm-v1-production-drift.md).

## `migrations/` — v2

The accepted target schema (`docs/design/crm-data-model-v2.md`, ADR-0002). It is
a **cutover**, not a layered migration: apply it to an empty `public` schema.

| File | Contents |
|---|---|
| `0001_schema_v2.sql` | Tables, composite FKs, exclusion constraint, monthly `events` partitions, `updated_at` triggers |
| `0002_functions_v2.sql` | The RPC surface the agent and the CRM call; no direct table writes |
| `0003_roles_v2.sql` | `migrator` / `crm_app` / `agent_rw` / `analytics_ro` and grants |

Apply in that order. `0003` creates NOLOGIN group roles; the login roles (with
passwords from Coolify env) are created per environment, outside the repo.

## `migrate/` — v1 → v2 data

The v1 database held one Artist, its price book and one staff user; Leads were
disposable. Two idempotent scripts copy what matters, using `dblink` against the
v1 `postgres` database in the same cluster:

- `v1_to_v2_tenants.sql` — `artists`, `artist_settings`, `price_book`
- `v1_to_v2_users.sql` — `users`, `notes` (run after Laravel migrations)

## `backup-v2.sh`

Nightly logical backup of `vaif_crm` (custom format) plus the cluster roles
(login roles/passwords are not in a database dump). Retention is 30 days and
every dump is validated with `pg_restore --list` before it replaces the
previous one.

Installed on the Coolify host as `/opt/vaif-crm/backup-v2.sh`, run by
`/etc/cron.d/vaif-crm-backup` at **03:15 UTC**:

```bash
install -m 700 backup-v2.sh /opt/vaif-crm/backup-v2.sh
printf '15 3 * * * root /opt/vaif-crm/backup-v2.sh >> /var/log/vaif-crm-backup.log 2>&1\n' \
  > /etc/cron.d/vaif-crm-backup
```

Files land in `/var/backups/vaif-crm/` (mode 600). A restore into a scratch
database has been tested:

```bash
createdb vaif_restore_test
pg_restore -d vaif_restore_test --no-owner < vaif_crm-<ts>.dump
```

A dump on the same host does not survive losing the host — add an off-host copy
(rsync/S3/rclone) once the destination is chosen.

## `cutover-v2.sh`

Guarded cutover helper (run on the Coolify host):

```bash
CONFIRM=yes ./packages/crm/db/cutover-v2.sh          # dump v1, rebuild vaif_crm, migrate tenants, create login roles
STAGE=app CONFIRM=yes ./packages/crm/db/cutover-v2.sh # Laravel migrations + users/notes, after the v2 image is deployed
```

The v1 database is never modified and a full dump is written to
`/tmp/crm-v1-pre-cutover-<ts>.dump` before anything is dropped.

## `tests/v2_contract.sql`

One transaction, rolled back at the end, that fails loudly. It covers tenant
isolation, phone normalisation, one-open-quote, manual deposit confirmation,
double-booking refusal by the exclusion constraint, availability windows,
promotion snapshotting and LGPD anonymisation.

```bash
createdb vaif_v2_test
psql -d vaif_v2_test -v ON_ERROR_STOP=1 -f migrations/0001_schema_v2.sql
psql -d vaif_v2_test -v ON_ERROR_STOP=1 -f migrations/0002_functions_v2.sql
psql -d vaif_v2_test -v ON_ERROR_STOP=1 -f migrations/0003_roles_v2.sql
psql -d vaif_v2_test -v ON_ERROR_STOP=1 -f tests/v2_contract.sql
```

CI runs exactly this on `postgres:17-alpine` (`.github/workflows/crm-db.yml`), and
also restores the production baseline dump to prove it is still restorable.

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

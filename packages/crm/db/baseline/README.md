# Production schema baseline (v1)

This directory holds the **actual** schema deployed on `crm.vaif.com.br`, not the
desired schema. It exists so the repo can answer "what is in production?" and so
CI can apply the same shape when testing v2 migrations.

## Files

| File | What it is |
|---|---|
| `0001_production.sql` | Plain SQL, schema-only dump. Reviewable and diffable. |
| `0001_production.sql.gz` | The exact artifact downloaded from the Coolify host. |

## Provenance

- **Captured:** 2026-10-28
- **Source:** self-hosted Coolify Postgres for the CRM
- **Command:** `pg_dump --schema-only` (no `--data`, zero `COPY` blocks)
- **Server:** PostgreSQL **17.7**; dump written by `pg_dump` **17.7**
- **Contents:** 20 tables, 31 functions, 14 indexes, 5 RLS policies, 0 triggers,
  0 explicit `GRANT`/`REVOKE` statements, owner `postgres`, no extensions

The dump starts with `\restrict <token>` and ends with `\unrestrict <token>`.
Those are pg_dump 17.7 anti-injection markers; they require `psql` 17.6+ to
restore. If you need an older client, strip both lines first.

## Regenerating

```bash
docker exec <postgres-container> pg_dump -U postgres -d postgres --schema-only \
  | gzip > 0001_production.sql.gz
```

Dump and compress in that order. Never commit a data dump: it contains Lead PII.

## What this baseline proves

It is the evidence behind `docs/design/crm-v1-production-drift.md`. Read that
before changing the design or writing a migration — several assumptions in the
old migration files do not hold in production (missing tenant foreign keys, two
missing columns, partial RLS, no roles).

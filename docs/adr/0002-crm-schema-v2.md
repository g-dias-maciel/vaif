---
Status: accepted
Date: 2026-10-27
---

# CRM data model v2: shared-schema tenancy with Lead/Project split

The CRM/SDR database must serve **1,000+ Artists** (VAIF's clients), each with
thousands of Leads per year. We will rebuild it as a shared-schema Postgres
database: every tenant-owned row carries `artist_id`, parents expose
`unique (artist_id, id)`, and children reference them with composite foreign
keys so cross-tenant references are impossible at the database level. A **Lead**
is a person (unique per Artist phone); a **Project** is one tattoo job for that
Lead, so returning customers and multi-tattoo deals are representable. Bookings
and blocks share one `calendar_entries` table guarded by a GiST exclusion
constraint, and `events` becomes an append-only, monthly-partitioned log with
`bigint` sequence PKs. Agent-facing SQL functions take an explicit `artist_id`
(no session GUC) and are the only surface n8n writes through. The database runs
on the self-hosted Coolify Postgres; Notion sync is removed; deposits are
confirmed manually until a payment provider is introduced.

**Considered Options**:
- *Schema-per-tenant.* Rejected: at 1,000 tenants it means ~15,000 relations,
  1,000 migration targets and painful cross-tenant analytics; shared schema with
  `artist_id` plus composite FKs is stronger and simpler.
- *Keep the current wide `leads` table.* Rejected: it cannot model a returning
  Lead or two tattoo jobs in one conversation, and it mixes Notion sync state
  with domain state. The recent `tattoos` refactor removed a phantom table but
  left the 1 Lead = 1 job assumption in place.
- *Keep RLS as the tenancy boundary.* Rejected for this topology: only Laravel
  and n8n connect, and the `app.artist_id` session GUC is unsafe under
  transaction-mode pooling. RLS is removed; it would only return, via a new ADR,
  if Artists ever got direct database access.
- *Hash-partition by `artist_id` now.* Rejected: it complicates global queries
  and uniqueness; time-range partitions solve the actual need (retention).
- *Keep Notion as a parallel surface.* Rejected: it duplicates the CRM, needs a
  per-Artist token at 1,000 Artists, and was a large share of write volume and
  failure modes. The CRM is the only surface.
- *Automatic Pix confirmation in v2.* Rejected for now: it adds a provider,
  webhooks, fees and reconciliation before the first live Artist. Manual
  confirmation ships first; the idea is recorded separately.

**Consequences**:
- The Laravel models, Blade board and ~20 n8n Postgres nodes are rewritten in
  one cutover. There are no live Artists, so no data migration is required; the
  v1 tables stay untouched until the cutover.
- `events` retention becomes `DROP PARTITION`. Retention: 24 months of hot
  Events, 5 years of Lead records, then anonymization. There is no outbox in v2.
- Agent writes go through RPCs only; `agent_rw` has no table grants, functions
  pin `search_path`, and `public` is no longer writable by default.
- This supersedes the RLS clause of ADR-0001: Artists never connect to Postgres
  directly, so RLS is removed; tenant integrity is enforced by composite foreign
  keys and least-privilege roles.
- Notion tables, columns and workflows are deleted, not migrated. If a future
  integration needs at-least-once delivery, a generic outbox is reintroduced as
  an additive migration (design kept in Appendix C of the design doc).
- Full DDL, indexing, capacity estimates and the phased migration plan live in
  `docs/design/crm-data-model-v2.md`.

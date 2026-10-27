---
Status: proposed
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
(no session GUC) and are the only surface n8n writes through.

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
  transaction-mode pooling. RLS becomes optional defense-in-depth if Artists
  ever get direct database access.
- *Hash-partition by `artist_id` now.* Rejected: it complicates global queries
  and uniqueness; time-range partitions solve the actual need (retention).

**Consequences**:
- The Laravel models, Blade board and ~20 n8n Postgres nodes are rewritten in
  one cutover. There are no live Artists, so no data migration is required; the
  v1 tables stay untouched until the cutover.
- `events` retention becomes `DROP PARTITION`; the outbox is purged separately.
- Agent writes go through RPCs only; `agent_rw` has no table grants, functions
  pin `search_path`, and `public` is no longer writable by default.
- If accepted, this supersedes the RLS clause of ADR-0001: RLS is no longer
  required for the agent path, because tenant integrity is enforced by composite
  foreign keys and least-privilege roles.
- Full DDL, indexing, capacity estimates and the phased migration plan live in
  `docs/design/crm-data-model-v2.md`.

---
Status: accepted
---

# Laravel native auth + app-layer authorization; Postgres RLS reserved for the agent path

> **Superseded in part (2026-10-27):** the RLS clause of this ADR is superseded
> by [ADR-0002](0002-crm-schema-v2.md). Artists access data only through the CRM,
> so RLS is removed and tenant isolation is enforced by `artist_id` + composite
> foreign keys + least-privilege roles. The auth decision below still stands.

The Artist-facing CRM is a Laravel app talking to the same Supabase Postgres the SDR agent writes to. We decided to use Laravel's native auth (Breeze/Fortify) with a `users` table carrying a `role` (`artist` | `staff`) and an `artist_id` foreign key, and to enforce authorization in Laravel policies/middleware. The app connects through a service-role DB connection that bypasses Postgres RLS; RLS stays in place only for the n8n/Beatriz write path.

**Considered Options**:
- *Supabase Auth (GoTrue) with JWT validation inside Laravel.* Rejected — non-idiomatic in Laravel, duplicates the identity store, and adds JWT plumbing for no benefit given the app already needs its own role/`artist_id` mapping.
- *Keep Postgres RLS as the authorization layer for the app (map `auth.uid()` → `artist_id`).* Rejected — couples the app to Supabase's auth model and to the per-session `app.artist_id` setting the agent relies on, and makes staff's cross-artist read-only access awkward.

**Consequences**:
- Authorization for the CRM lives in Laravel, so the Postgres RLS policies must **not** be removed — the agent path still depends on them. Any future data access from the app must go through Eloquent models, not raw SQL that assumes RLS.
- `users.artist_id` is nullable (staff users have no Artist).
- Identity is split by convention: Supabase auth users (if any) are unrelated to Laravel `users`.

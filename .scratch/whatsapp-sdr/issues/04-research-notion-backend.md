# Research: Notion as an agent backend

Type: research
Status: resolved — findings at `research/notion-agent-backend.md` (2026-07-28)
Blocked by: none

## Question

Gather the facts the "Sequencing: CRM-first vs agent-first" decision waits on, from primary sources: Notion API rate limits and burst behavior, query/filter/pagination capabilities, relational-modeling limits, content/block size limits, and latency characteristics — judged as a live agent state store at VAIF's volume (~6 artists, 6–12 leads/day each, tens of artists within 6 months). Plus a factual capability scan of self-hostable alternatives a solo builder could run (plain Postgres via n8n, Supabase, Twenty, Baserow/NocoDB) — capabilities and hosting model only, no recommendation. Output: a findings file with a cited source per claim.

## Answer

Findings captured (primary-sourced, each claim cited): [Research: Notion as an agent backend — findings](../research/notion-agent-backend.md).

Load-bearing facts for the "Sequencing: CRM-first vs agent-first" decision:

- **Notion rate-limits on two axes:** ~3 req/s average **per connection** (bursts allowed, size undocumented) plus a plan-scaled **per-workspace** limit; 429/529 carry `Retry-After`; limits may change anytime without a version bump.
- **Volume is not the binding constraint.** Even at 30 artists (~2,900 msgs/day high estimate) the agent averages ≤0.2 rps vs the 3 rps limit. The real issues are **bursts** (each message = a 3–6-call chain) and whether **one public connection across all client workspaces shares a single 3 rps budget** — the docs don't say. That gap itself is a risk.
- **Transcript appends fit** (≤100 blocks/call, ≤2000 chars/rich-text, ≤1000 blocks + 500KB/request), but transcripts-as-blocks grow forever (no documented per-page cap) and read-back costs one call per 100 blocks — a slow, paging query pattern:
  - formulas over relations evaluate only the first 25 refs;
  - multi-layer relation rollups "may not return correct results" (Notion's own words);
  - queries hard-cap at 10,000 results, with Notion documenting 503s from datastore timeouts and suggesting splitting databases past "several dozen thousand pages."
- **Writes are non-atomic** (documented 409 `conflict_error`); search "is not immediate" — create-then-find can miss.
- **No official latency figures or SLA** — only a 60s max request time (503) and webhook delivery of "most within a minute," **at-most-once, unordered, IDs-only** payloads.
- **API churn risk:** restructured at Notion-Version 2025-09-03 (databases → data sources; current 2026-03-11); old-version connections break if a user adds a data source to a template database — relevant because VAIF ships a template clients extend.
- **n8n Notion node** covers database-page CRUD, block append, and search — **no block update/delete, comments, or file ops** (HTTP Request node is the fallback); it can serve as an AI-agent tool; the Notion Trigger only fires on "page added/updated in database."
- **n8n speaks the alternatives natively:** full Postgres node (raw SQL, upsert, transaction batching) + a **Postgres Chat Memory** node built for AI agents + a row-CRUD Supabase node.
- **Self-hostable alternatives (capabilities + hosting only):** Supabase self-host = the full stack (Postgres, PostgREST, Auth, Realtime, Storage, Studio, Edge Functions; **4 GB RAM min**) but you own backups/HA; Twenty = AGPLv3-core CRM (Postgres+Redis, 2 GB RAM, Docker Compose) with REST+GraphQL auto-generated from the workspace schema + record webhooks; Baserow = MIT core, REST+WebSocket APIs; NocoDB = Sustainable Use License (source-available), single container, can sit on external Postgres, REST API.

Resolve-verdict: facts gathered; no recommendation (decision belongs to "Sequencing: CRM-first vs agent-first"). Unverified items are listed in the findings file's Gaps section — chiefly: whether one public connection shares a 3 rps budget across all installed client workspaces (architecturally load-bearing for VAIF's per-artist-template model), any numeric per-workspace limit or burst allowance, any official latency/SLA, the per-page block cap, and what triggers the undocumented-in-text HTTP 406 `row_limit_exceeded`.

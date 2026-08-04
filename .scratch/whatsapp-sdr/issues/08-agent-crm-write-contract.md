# Agent↔CRM write contract

Type: grilling
Status: resolved
Blocked by: none

## Question

Define, backend-agnostic, what the SDR agent reads and writes per Lead: fields (contact, qualification answers, status), events (qualified, booked, handed-off), and where conversation transcripts live. This is the contract any backend — Notion or self-built — must satisfy; "Sequencing: CRM-first vs agent-first" weighs it against the "Research: Notion as an agent backend" findings.

## Answer

4/4 decisions locked. Full contract: [design/crm-write-contract.md](../design/crm-write-contract.md).

1. **Concrete Postgres schema** — Beatriz writes to Postgres (Supabase, n8n-native). Artist CRM (Notion → self-built) is a downstream sync layer. Separate concern, separate task ticket.
2. **Flat `leads` table for v1** — 23 fields in one row. Extracted `pricing`, `calendar`, `artists` for per-artist config. Append-only `events` table for audit + LLM context injection. Simple n8n queries, no joins.
3. **Operations interface** — 13 CRUD operations + 3 read-only lookups mapped to SQL. Deposit-before-booking flow corrected: accept price → send PIX → human confirms deposit → auto-book slot. 48h deposit timeout → `perdido`.
4. **Multi-tenancy: `artist_id` FK + RLS** — every query scoped to the current artist via WAHA session metadata. Postgres Row-Level Security blocks miswritten queries at the database level. Transcripts in Postgres Chat Memory (keyed by lead_id).
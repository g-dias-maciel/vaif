# PRD — WhatsApp SDR Closer (Beatriz)

Labels: implementation

Full product spec for Beatriz, VAIF's AI SDR agent that works artists' inbound WhatsApp leads.

**Status**: 49 user stories across Leads, Artists, and VAIF Partner. 9 implementation tickets broken out.

See the full PRD in the repo at `.scratch/whatsapp-sdr/issues/12-sdr-closer-prd.md`.

## Architecture summary

- **Runtime**: n8n (single shared workflow), GPT-4o-mini via OpenRouter, Postgres Chat Memory
- **Transport**: WAHA self-hosted (GOWS engine) → Cloud API bridge at 10 artists
- **Database**: Postgres (Supabase) — 5 tables (leads, artists, pricing, calendar, events), RLS multi-tenancy
- **CRM**: Postgres is operational; artists keep Notion as their CRM (downstream sync)
- **Onboarding**: Partner submits stub form → WAHA session provisioned → Beatriz setup conversation collects pricing/calendar → artist goes live

## Dependencies

- 9 implementation tickets (#1–#9) break this spec into tracer-bullet vertical slices
- See each ticket for its blocking edges

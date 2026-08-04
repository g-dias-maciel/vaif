# WhatsApp SDR closer

Type: wayfinder map
Status: build phase — PRD at [issues/12-sdr-closer-prd.md](issues/12-sdr-closer-prd.md), 9 implementation tickets on GitHub ([#1](https://github.com/g-dias-maciel/vaif/issues/1)–[#9](https://github.com/g-dias-maciel/vaif/issues/9))

## Implementation tickets (GitHub)

| Ticket | GitHub | Blocked by |
|---|---|---|
| Postgres schema + Supabase setup | [#1](https://github.com/g-dias-maciel/vaif/issues/1) ✅ done | None |
| Telegram + Postgres | [#2](https://github.com/g-dias-maciel/vaif/issues/2) | #1 |
| WhatsApp agent | [#3](https://github.com/g-dias-maciel/vaif/issues/3) | #2 |
| Pricing + booking | [#4](https://github.com/g-dias-maciel/vaif/issues/4) | #3 |
| Handoff + negotiation | [#5](https://github.com/g-dias-maciel/vaif/issues/5) | #3 |
| Artist onboarding | [#6](https://github.com/g-dias-maciel/vaif/issues/6) | #2 |
| Notion sync | [#7](https://github.com/g-dias-maciel/vaif/issues/7) | #3 |
| Self-serve portal | [#8](https://github.com/g-dias-maciel/vaif/issues/8) | #6 |
| Testing harness | [#9](https://github.com/g-dias-maciel/vaif/issues/9) | #4, #5 |

## Destination

A build-ready spec for VAIF's agentic WhatsApp SDR closer — the product that works artists' inbound WhatsApp leads and closes them into bookings — with the data-foundation decision locked (Notion vs self-built CRM, and in what order) and the monorepo layout that houses the work. The map is done when nothing is left to decide before building starts.

## Notes

- Domain: VAIF is a Brazilian agency serving tattoo artists only. 6 artist clients today, targeting tens within 6 months; each artist gets ~6–12 inbound WhatsApp leads/day. Products: ads management (run by the non-tech partner), a client-facing Notion CRM template (artists use it directly), and the SDR agent (this effort).
- Stack facts: WAHA self-hosted on a Coolify server; n8n is the agent/automation runtime; the agent prototype currently runs against a Telegram bot as testbed. Landing page is PHP at `packages/lp/`.
- Builder: solo tech founder, full-time dev elsewhere, agency on the side; builds with AI-agentic development. Partner handles ads + client management.
- Language: agent conversations are Brazilian Portuguese; specs, tickets, and this map are English.
- Skills every session consults: `/grilling` + `/domain-modeling` (grilling tickets), `/research` (research tickets), `/prototype` (prototype tickets).
- Standing preferences: plan-don't-do — the map's product is decisions, not deliverables. Refer to maps and tickets by name, never bare numbers.
- Motivations logged at chart time (the Sequencing ticket must weigh all four): anticipated Notion limits, CRM-as-platform vision, product value/monetization, clean-foundation instinct.

## Decisions so far

- [Monorepo layout for VAIF projects](issues/01-monorepo-layout.md) — `/var/www/vaif` is the monorepo; structure: `packages/{crm,flows,infra,lp}`, `design/`, `docs/`, `.scratch/`; landing page migrates in as `packages/lp/`; agentic context stays simple (single CONTEXT.md, AGENTS.md, .scratch/ — upgrade lazily).
- [Repo + local-tracker setup](issues/02-repo-and-tracker-setup.md) — skeleton laid: `packages/` with LP migrated, `design/` for contracts, `docs/agents/issue-tracker.md` recording local-markdown conventions, root `.gitignore`, updated README/AGENTS/CONTEXT.
- [Research: WhatsApp transport options](issues/03-research-whatsapp-transport.md) — WAHA now fully free + light enough for tens of tenants on one VPS (GOWS/NOWEB engines), but unofficial = ToS risk best mitigated by reply-only inbound behavior; Cloud API supports per-Artist numbers/WABAs, inbound replies free in the 24h window until the 2026-10-01 pricing change (~$1.20–2.45/artist/month after), coexistence path needs Solution/Tech-Partner status.
- [Research: Notion as an agent backend](issues/04-research-notion-backend.md) — volume is not the wall (≤0.2 rps avg vs 3 rps limit); real risks are the unanswered shared-budget question across client workspaces, relational correctness cliffs (25-ref formulas, multi-layer rollups), non-atomic writes, delayed search indexing, and API churn at 2025-09-03; n8n speaks Postgres/Supabase natively (incl. Postgres Chat Memory), and capable self-hosted alternatives exist (Supabase full stack, Twenty CRM, Baserow/NocoDB).
- [SDR conversation design](issues/05-conversation-design.md) — Beatriz, the named assistant persona, follows the artist's closing checklist: 5-phase qualification flow (14 placements, 4 body-zones inferred — no cm, style match, reference pics, availability) into autonomous pricing (placement × body-zone × session-duration table, dynamic creep) and booking; PIX deposit; Instagram-post contrapartida negotiation with per-artist % floor; below-floor + cover-up + 6 other handoff triggers; best-guess fallback; full lead-card schema (20 fields, 5 pipeline states). Contract at [design/conversation-contract.md](design/conversation-contract.md).
- [Agent runtime stack](issues/06-agent-runtime-stack.md) — n8n end-to-end host; GPT-4o-mini via OpenRouter; Postgres Chat Memory (n8n native); manual JSON export + git versioning, Telegram testbed + eval-set testing; $100/month OpenRouter hard cap as cost airbag. Full decision doc at [design/runtime-stack.md](design/runtime-stack.md).
- [WhatsApp transport decision](issues/07-whatsapp-transport-decision.md) — V1: WAHA (GOWS engine, already running, $0, reply-only inbound); business numbers only (no personal WhatsApp); switch trigger at first ban/suspension or 30 artists (whichever first); Cloud API bridge starts at 10 artists (Business verification, App Review, Embedded Signup v4); migration via coexistence onboarding. Full decision doc at [design/transport-decision.md](design/transport-decision.md).
- [Agent↔CRM write contract](issues/08-agent-crm-write-contract.md) — Beatriz writes to Postgres (Supabase); artist CRM is a downstream sync; flat `leads` table (23 fields) + `artists`/`pricing`/`calendar`/`events`; 13 CRUD + 3 RO operations; deposit-before-booking flow (human confirms PIX, then auto-book); multi-tenancy via `artist_id` FK + RLS. Full contract at [design/crm-write-contract.md](design/crm-write-contract.md).
- [Sequencing: CRM-first vs agent-first](issues/09-sequencing-crm-vs-agent.md) — **Agent-first**: build Beatriz on WhatsApp now, self-built CRM is a parallel investment. Full pipeline sync to Notion (creation through fechado/perdido) — artists keep their familiar tool. Postgres schema (08) is the clean foundation. Self-built CRM not spawned as a map — `packages/crm/` waits. Full decision at [design/sequencing-decision.md](design/sequencing-decision.md).
- [Prototype: SDR persona on Telegram testbed](issues/10-prototype-sdr-telegram.md) — Beatriz system prompt at `packages/flows/prompts/beatriz-system.md`; 10 test conversation scripts at `packages/flows/test/conversation-fixtures.md`; setup README at `packages/flows/beatriz-telegram/README.md`. Awaiting user test run against Telegram bot.

- [Artist onboarding and tenancy](issues/11-artist-onboarding-tenancy.md) — One shared n8n workflow (session-routed); slug-based WAHA sessions; formal artist lifecycle state machine (`stub`→`onboarding`→`live`→`suspended`→`offboarded`); partner-triggered stub creation via n8n Form; Beatriz runs dynamic setup conversation for pricing/calendar; self-serve portal phased after prototype validation; connection recovery via re-issued QR. Full decision at [design/artist-onboarding-tenancy.md](design/artist-onboarding-tenancy.md).

## Not yet specified

- **Meta Business verification + official API signup** — transport decision says "start bridge at 10 artists"; task ticket.
- **Lead-source attribution** (which ad/campaign brought the lead) — placeholder field in `leads.lead_source`. Mechanism TBD.
- **Self-built CRM product spec** — not spawned by this map (agent-first). Separate wayfinding map for `packages/crm/` when the time comes.
- **Notion migration for live artists** — not happening in this map. Artists stay on Notion until the self-built CRM ships (separate map).

## Out of scope

- **Pricing/packaging of the agent product** — business decision, not needed for a build-ready spec.
- **Ads-management tooling** — separate business line, no bearing on the SDR spec.
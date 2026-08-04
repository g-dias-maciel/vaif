# Sequencing: CRM-first vs Agent-first

Resolved from grilling ticket 09, 2026-07-28. Built on [research/notion-agent-backend.md](../research/notion-agent-backend.md) and the [CRM write contract](crm-write-contract.md).

## Decision: Agent-first

**Build the SDR agent (Beatriz on WhatsApp) now. The self-built CRM is a parallel investment — separate timeline, separate map.**

Rationale, weighed against the four chart-time motivations:

| Motivation | How agent-first serves it |
|---|---|
| **Anticipated Notion limits** | Beatriz writes to Postgres (08). Notion is a downstream sync — no API cliffs, no relational correctness risk, no non-atomic writes. Limit risk is removed from the critical path. |
| **CRM-as-platform vision** | `packages/crm/` exists, Postgres schema is designed (08). When the CRM map runs, it reads the same backend. Agent-first buys runway to spec the CRM properly without rushing. |
| **Product value + monetization** | The SDR agent is the revenue-generating product. Artists pay for closed bookings, not a CRM. Ship value → revenue → fund the CRM at a sustainable pace. |
| **Clean-foundation instinct** | The Postgres schema (flat leads table, RLS, events log) is a clean foundation. The Notion sync is thin — one n8n HTTP node per artist workspace, replaceable when the CRM arrives. |

## Notion Sync: Full Pipeline Mirror

All leads are synced to the artist's Notion workspace, not just handoffs. Rationale: ads partner can read lead data for retargeting/custom audiences without Postgres credentials; artists see their pipeline in their familiar tool from day one; the Notion API overhead per lead is identical regardless of when we fire it.

| Trigger | Notion action |
|---|---|
| Lead created | Page in "Novos" view |
| Qualification fields filled | Update existing page with placement, style, body-zone, etc. |
| Quote sent | Move to "Orçamento" view, update price fields |
| Handoff triggered | Move to "Aguardando Artista" view, add handoff reason |
| Deposit confirmed + slot booked | Move to "Agendados" view, update date/slot/deposit |
| Fechado | Move to "Fechados" view |
| Perdido | Move to "Perdidos" view |

Sync volume: ~6-12 leads/artist/day × 6 artists = ~36-72 pages/day. Well within Notion's 3 rps limit. Contention risk (per the research) remains — shared Notion workspace budget and API churn — but the critical path (Beatriz closing leads) is insulated in Postgres.

Sync pipeline lives in n8n: the Postgres node writes the lead, the same workflow fires a Notion HTTP node. Not a separate service.

## Self-Built CRM

**Not spawned by this decision.** `packages/crm/` stays as a directory with a Postgres schema waiting. When the time is right, it becomes its own wayfinding map — own destination, own tickets, own frontier. The sequencing decision here says: CRM is not the next thing.

## What Changes for the 6 Live Artists

**Nothing.** Their Notion CRM template remains their CRM. Beatriz's data appears in it as new pages — the same structure they already use. No migration. The Notion sync pipeline mirrors Beatriz's operational Postgres into their existing workspaces.

When the self-built CRM ships, the sync pipeline flips: Notion becomes read-only archive, the new CRM becomes the primary view. But that's a future map's concern.

## Notion API Cost

Notion's API is free. No usage charges. The only cost is the sync pipeline's n8n execution time — negligible at this volume.

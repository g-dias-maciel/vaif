# 19 — Notion sync pipeline

Labels: implementation

## What to build

Downstream sync that mirrors every pipeline state change from Postgres to the artist's Notion workspace. After each CRM write in the Beatriz workflow, a Notion HTTP node fires with the artist's Notion API token — creating pages, updating fields, moving pages between views. Covers the full pipeline: Novo → Qualificando → Orçamento → Aguardando Depósito → Agendado → Fechado/Perdido.

## Acceptance criteria

- [ ] Notion API connection configured per artist (token from artist's Notion integration)
- [ ] Lead created → new page in artist's Notion "Novos" view with contact fields
- [ ] Qualification fields filled → page updated with those fields
- [ ] Quote sent → page moved to "Orçamento" view, price fields updated
- [ ] Handoff triggered → page moved to "Aguardando Artista" view, handoff_reason set
- [ ] Deposit confirmed + slot booked → page moved to "Agendados" view, date/slot/deposit updated
- [ ] Fechado → page moved to "Fechados" view
- [ ] Perdido → page moved to "Perdidos" view
- [ ] Sync fires on every state transition — no delays, no batch processing
- [ ] Sync failure does not block Beatriz's conversation flow (fire-and-forget, logged)
- [ ] Notion API rate limits respected (max 3 rps)

## Blocked by

- #3 — Beatriz on WhatsApp

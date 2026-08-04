# Sequencing: CRM-first vs agent-first

Type: grilling
Status: resolved
Blocked by: none

## Question

The map's central decision: ship the SDR agent against the Notion CRM now and migrate later, or build the self-developed CRM first. Weigh the "Research: Notion as an agent backend" findings and the "Agent↔CRM write contract" against: solo part-time build capacity (AI-agentic development), 6 live client workspaces that migration would displace, and the four motivations logged at chart time (anticipated Notion limits, CRM-as-platform vision, product value/monetization, clean-foundation instinct). The answer names what gets built next — and whether a CRM product spec spawns as a fresh map.

## Answer

2/2 decisions locked. Full decision: [design/sequencing-decision.md](../design/sequencing-decision.md).

1. **Agent-first** — build Beatriz on WhatsApp now. Beatriz writes to Postgres (08); Notion is a downstream sync — no API cliffs, no relational risk. Postgres schema is already the clean foundation. Ship revenue-generating product, fund the CRM at a sustainable pace. Artists keep their Notion CRM unchanged.
2. **Full pipeline sync to Notion** — every lead mirrored in the artist's Notion workspace (creation through fechado/perdido), not just handoffs. Enables ads retargeting without Postgres credentials, artists stay in their familiar tool. Thin n8n sync pipeline — one HTTP node per trigger. Volume (~36-72 pages/day) well within Notion limits.
3. **Self-built CRM not spawned** — `packages/crm/` waits for its own wayfinding map, separate timeline. No CRM migration for the 6 live artists — their Notion templates stay as-is with Beatriz's data flowing in.
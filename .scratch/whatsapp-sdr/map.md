# WhatsApp SDR closer

Type: wayfinder map
Status: active

## Destination

A build-ready spec for VAIF's agentic WhatsApp SDR closer — the product that works artists' inbound WhatsApp leads and closes them into bookings — with the data-foundation decision locked (Notion vs self-built CRM, and in what order) and the monorepo layout that houses the work. The map is done when nothing is left to decide before building starts.

## Notes

- Domain: VAIF is a Brazilian agency serving tattoo artists only. 6 artist clients today, targeting tens within 6 months; each artist gets ~6–12 inbound WhatsApp leads/day. Products: ads management (run by the non-tech partner), a client-facing Notion CRM template (artists use it directly), and the SDR agent (this effort).
- Stack facts: WAHA self-hosted on a Coolify server; n8n is the agent/automation runtime; the agent prototype currently runs against a Telegram bot as testbed. Landing page is PHP at `/var/www/vaif-lp` (on GitHub).
- Builder: solo tech founder, full-time dev elsewhere, agency on the side; builds with AI-agentic development. Partner handles ads + client management.
- Language: agent conversations are Brazilian Portuguese; specs, tickets, and this map are English.
- Skills every session consults: `/grilling` + `/domain-modeling` (grilling tickets), `/research` (research tickets), `/prototype` (prototype tickets).
- Standing preferences: plan-don't-do — the map's product is decisions, not deliverables. Refer to maps and tickets by name, never bare numbers.
- Motivations logged at chart time (the Sequencing ticket must weigh all four): anticipated Notion limits, CRM-as-platform vision, product value/monetization, clean-foundation instinct.

## Decisions so far

(none yet — charted 2026-07-28, research in flight)

## Not yet specified

- **Meta Business verification + official API signup** — only specifiable once "WhatsApp transport decision" lands; likely a task ticket.
- **Migrating the 6 live artist workspaces off Notion** — hangs on "Sequencing: CRM-first vs agent-first"; if agent-first wins, this likely leaves this map entirely.
- **Lead-source attribution** (which ad/campaign brought the lead) — shape depends on "SDR conversation design" and "Agent↔CRM write contract".
- **Human-handoff edge cases** beyond what "SDR conversation design" settles (abuse, off-hours, artist unresponsive).
- **Self-built CRM product spec** — if "Sequencing: CRM-first vs agent-first" lands CRM-first, this spawns as a fresh wayfinding map, not tickets here.

## Out of scope

- **Pricing/packaging of the agent product** — business decision, not needed for a build-ready spec.
- **Ads-management tooling** — separate business line, no bearing on the SDR spec.

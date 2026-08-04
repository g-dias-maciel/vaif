# Research: WhatsApp transport options

Type: research
Status: resolved
Blocked by: none

## Question

Gather the facts the "WhatsApp transport decision" waits on, from primary sources: (a) WAHA — engines and their tradeoffs, session model, per-session resource cost, stability and ban-risk profile with mitigations at low volume (~6–12 msgs/day/artist); (b) Meta's official WhatsApp Business Platform (Cloud API) — Brazil pricing per conversation/message category, the 24-hour service window, template-message rules for business-initiated messages, per-number/per-WABA requirements, and verification burden for small Brazilian businesses; (c) what a hybrid path (WAHA now, official later) concretely entails. Output: a findings file with a cited source per claim.

## Answer

Findings captured (primary-sourced, each claim cited): [Research: WhatsApp transport options — findings](../research/whatsapp-transport.md).

Load-bearing facts for the "WhatsApp transport decision":

- **WAHA is now fully free** (single open-source Core image since v2026.6.1, 2026-06-21): unlimited sessions, all storages, API-key security; old Plus/Pro Patreon tiers dropped. Only a $5/mo Community support tier remains.
- **Engines:** WEBJS/WPP drive a real Chromium (heavy ~3 CPU/2.5 GB at 10 sessions); NOWEB (Node) and GOWS (Go) are browserless — GOWS is WAHA's designated NOWEB replacement and the lightest (~0.1 CPU/200 MB/session, 500 sessions on 5–8 CPU/25 GB). Tens of one-session-per-Artist tenants fit comfortably on one VPS with GOWS/NOWEB.
- **Sessions = linked devices:** each WAHA session is one WhatsApp number QR-paired as a multi-device linked client; per-session webhooks, proxy, and tenant-id metadata are free.
- **WAHA itself states it is not safe** (their docs): WhatsApp "does not allow bots or unofficial clients," blocking is not guaranteed away, and they recommend official methods for critical business use. Their anti-ban playbook: reply-only behavior, human-like pacing, avoid user reports (~5–10 spam tags ≈ ban) — which matches VAIF's inbound-SDR pattern.
- **ToS basis for risk:** consumer ToS bans auto-messaging, non-personal use, and reverse-engineering/compatible-API creation; Business Terms §5g bans interacting with Business Services without Meta's prior written consent. Both allow account suspension/termination.
- **Cloud API pricing is per delivered template message** (conversation pricing died 2025-07-01). Brazil numbers, effective 2026-07-01: marketing $0.0625 / R$0.3217; utility & authentication $0.0068 / R$0.0350 per delivered message.
- **Today inbound-reply traffic on Cloud API is free** (non-template service messages in the 24h window free since Nov 2024; in-window utility templates free since July 2025). CTWA/Facebook-Page entry points make everything free for 72h.
- **⚠️ Imminent change — effective 2026-10-01:** service messages (incl. third-party-AI replies) become chargeable at the utility/authentication rate (~$0.0068 in Brazil ⇒ roughly **$1.20–2.45 per artist per month** at VAIF's 6–12 msgs/day), and in-window utility templates stop being free. (Meta's own AI agent moves to per-token billing 2026-08-01.)
- **24h window:** opens/resets with each user message; inside it, any free-form type; outside it, only pre-approved templates (marketing always charged).
- **Templates** need auto-review approval (up to 24h), are quality-policed, and new portfolios face a **250-unique-recipients/24h** limit (scales 2,000 → unlimited via verification + quality + utilization).
- **One number + WABA per Artist is supported** via Embedded Signup (incl. pt-BR); portfolios start capped at 2 numbers (20 after business verification). A number in active consumer-app use must be deleted first (banned numbers must win appeal first).
- **Partner-route onboarding:** App Review + (for >10 clients/week) Business Verification; Solution Partners share a Meta credit line, Tech Providers make each client attach its own payment method. New WABAs from Brazil Sold-To businesses are BRL/Facebook-Brasil (July 2026; mandatory migration by 2027-06-30).
- **Hybrid path is concrete but conditional:** consumer-app numbers must be deleted (~3 min release, history lost); business-app numbers can keep history and run app + API concurrently via "coexistence" onboarding (180-day history sync, one-shot within 24h, 20 msg/s cap) — **only through a Solution Partner/Tech Provider**, so partner status is a lead-time item. WAHA-side session history never migrates.

Resolve-verdict: facts gathered; no recommendation (decision belongs to "WhatsApp transport decision"). Unverified items are listed in the findings file's Gaps section — chiefly exact Oct-1 2026 Brazil service rates (publish by Sept 1), the Brazil-specific business-verification document list (e.g. CNPJ requirements), and per-engine ban-rate data (WAHA publishes none).

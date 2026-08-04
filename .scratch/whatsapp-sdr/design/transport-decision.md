# WhatsApp Transport Decision

Resolved from grilling ticket 07, 2026-07-28. Built on findings from [research/whatsapp-transport.md](../research/whatsapp-transport.md).

## 1. V1 transport: WAHA (unofficial)

Beatriz connects to artists' WhatsApp via WAHA, self-hosted on the existing Coolify server. WAHA Core (2026.6.1+) is fully free — unlimited sessions, all engines, all storages, API-key security, MCP server.

**Engine**: GOWS (Go WebSocket, no browser). At 6→tens of sessions:
- 10 sessions: 0.5 CPU / 1 GB
- 50 sessions: 1.5 CPU / 3 GB

Well within a single Coolify VPS.

**Why now, not Cloud API**: at 6 artists with reply-only inbound traffic, the ban-risk surface is narrow and the Cloud API onboarding overhead (Meta portfolio, WABA creation, number OTP, display-name review, template approval, App Review for Partner status) is weeks of process for a solo builder with a day job. WAHA is already running and costs $0.

## 2. Number architecture

**No artist's personal WhatsApp number gets paired to WAHA.** A ban would nuke the artist's personal account — contacts, chats, everything. Unacceptable.

- Artists with an existing separate WhatsApp Business number: pair that number.
- Artists with only one number (personal + business combined on same device): **must get a second number** before going live with the agent. VAIF helps them set it up (prepaid SIM or VoIP) but doesn't pay for it.

All paired numbers must be dedicated business lines — the agent is the only thing using them through WAHA.

## 3. Switch trigger

Two triggers, whichever fires first:

| Trigger | What happens |
|---|---|
| **Account-level action from WhatsApp** on any artist number — ban, suspension, CAPTCHA wall, forced-logout loop | No new artists go on WAHA. Existing artists migrate to Cloud API over N weeks. |
| **30 artists** on the platform | No new artists go on WAHA. Existing ones migrate. |

When the switch triggers, all new onboardings go straight to Cloud API. Existing WAHA artists are migrated in cohorts — the agent keeps working on WAHA during their migration window, then cuts over once their Cloud API setup is live.

## 4. Cloud API bridge — start at 10 artists

The Meta Partner process (Business verification, App Review, Embedded Signup) runs on Meta time — weeks to months. Starting it only when the switch trigger fires means dead air.

**Start the Partner process at 10 artists** — roughly 2-3 months from now at the current trajectory, leaving plenty of runway before hitting 30.

What to trigger at 10:
1. **Business verification** — VAIF as registered legal entity (CNPJ), authorized representative, business phone/email, domain verification.
2. **App Review** — `whatsapp_business_management` and `whatsapp_business_messaging` permissions at Advanced Access.
3. **Partner model decision** — Solution Partner (Meta line of credit, customers message immediately) vs Tech Provider (customers attach own payment method). Relevant for 20+ artists but not urgent at 10.
4. **Embedded Signup v4** — integration built and tested, ready to onboard the first Cloud API artist when needed.

## 5. Migration path (WAHA → Cloud API)

When an artist migrates:

| Artist's current setup | Migration path | History preserved? |
|---|---|---|
| Separate WhatsApp Business number, already paired to WAHA | Coexistence onboarding (Embedded Signup with Business-app-user flow) | Yes — 180 days of 1:1 chat history syncs |
| New dedicated number, never on WAHA | Standard Embedded Signup | N/A — no history to carry |

For coexistence: requires Solution Partner or Tech Provider status (same as what we trigger at 10 artists). The number stays alive in the artist's WhatsApp Business app while Cloud API takes over — the agent sends through Cloud API, the artist sees the conversation in their app. No delete-my-account drama.

WAHA-local data (session state, WAHA's own message store) does not migrate — only the WhatsApp Business app's chat history syncs. The CRM (Postgres, from ticket 08) holds the Lead profile data independently.

## 6. Cost summary

| Stage | Transport | Artists | Agent LLM | Transport cost | Total/month |
|---|---|---|---|---|---|
| V1 (now) | WAHA | 6 | GPT-4o-mini | $0 | ~$5 |
| Scale (6 months) | WAHA | 10-20 | GPT-4o-mini | $0 | ~$8-17 |
| Pre-switch | WAHA | 20-29 | GPT-4o-mini | $0 | ~$17-24 |
| Post-switch | Cloud API | 30+ | GPT-4o-mini | ~$1.20-2.45/artist | ~$61-98 |

Post-switch transport cost assumes Oct-2026 service-message pricing (~$0.0068/msg in Brazil, 6-12 msgs/day). Actual rates to be confirmed Sept 1, 2026 per Meta's published schedule.

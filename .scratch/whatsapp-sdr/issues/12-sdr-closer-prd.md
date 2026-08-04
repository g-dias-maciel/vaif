# WhatsApp SDR Closer — Product Spec

Type: spec
Status: ready-for-agent
Label: ready-for-agent
Blocked by: none

## Problem Statement

VAIF's tattoo artists receive 6–12 inbound WhatsApp leads per day from ads and organic discovery. Every lead wants to know the same things — price, placement, style, availability — and follow the same closing checklist. The artists themselves are tattooing, not selling. They respond slowly, miss leads, and lose bookings to faster competitors. The VAIF partner manually qualifies leads and passes them to artists, but at 6 artists and growing, this doesn't scale.

The artist's closing process is well-defined (a checklist they hand to assistants), but hiring human assistants per artist is uneconomical at VAIF's scale. The artists need a reliable, always-on assistant that works their inbound WhatsApp at the speed of chat, qualifies every lead, quotes correctly, and closes bookings — handing off to the human artist only when the situation exceeds the assistant's bounds.

## Solution

**Beatriz** — an AI SDR agent that lives on the artist's WhatsApp Business number, qualifies inbound leads, prices against the artist's placement × body-zone table, negotiates with Instagram-post contrapartida, books slots into the artist's calendar, and requests PIX deposits. Beatriz follows the 7-phase artist closing checklist faithfully and hands off to the human artist on cover-ups, below-floor offers, and other defined triggers.

Beatriz runs inside n8n, using GPT-4o-mini via OpenRouter for natural conversation in Brazilian Portuguese, Postgres (Supabase) as the operational CRM, and WAHA (self-hosted) as the WhatsApp transport. A shared Postgres schema with row-level security isolates data per artist. The artist's existing Notion CRM is kept in sync via a downstream pipeline — artists see their pipeline in their familiar tool from day one.

One n8n workflow serves all artists, resolving the correct artist context from the incoming WAHA session slug, with RLS enforcing data isolation.

## User Stories

### Lead (the person wanting a tattoo)

1. As a lead, I want to message the artist's WhatsApp and get an instant response, so I don't wait hours or days for a reply.
2. As a lead, I want the assistant to introduce herself transparently ("Beatriz, assistente do artista"), so I know I'm talking to an assistant and not the artist.
3. As a lead, I want Beatriz to use my name during the conversation, so the interaction feels personal.
4. As a lead, I want Beatriz to remember everything I've already said, so I never have to repeat myself.
5. As a lead, I want to talk about what I want in natural language (placement, style, size), so I don't need to fill forms.
6. As a lead, I want to share reference pictures of the tattoo I want, so the artist can see my vision.
7. As a lead, I want to get a clear price in both cash and installment (up to 6x), so I can choose how to pay.
8. As a lead, I want the price to be explained with context (process, quality, artist expertise), not just a number.
9. As a lead, I want to ask questions about the process before seeing the price, so I feel informed.
10. As a lead, I want space to respond to the price before Beatriz says anything else, so I don't feel pressured.
11. As a lead, I want to negotiate the price if it feels too high, so I can reach a value that works for me.
12. As a lead, I want to book a date and time directly in the chat, so I don't need another conversation or channel.
13. As a lead, I want to pay the deposit via PIX immediately after booking, so my slot is secured.
14. As a lead, I want written confirmation of my booking (date, time, address, deposit paid), so I have a record.
15. As a lead, I want to talk to the real artist when my case is complex (cover-up, unusual budget, returning client), so I get personalized attention.
16. As a lead contacting the studio outside business hours, I want to know when I'll get a real response, so I don't wait anxiously.
17. As a lead whose requested style doesn't match the artist, I want a graceful explanation and an alternative suggestion, so I can either adapt or look elsewhere.
18. As a lead whose requested placement the artist doesn't do, I want a clear decline with a chance to suggest another placement, so I'm not left confused.
19. As a returning client, I want the assistant to recognize me and loop in the artist, so my existing relationship is respected.
20. As a lead sending voice messages, I want to be asked to type instead (once), so I understand the assistant works with text.

### Artist

21. As an artist, I want every inbound WhatsApp lead answered immediately, so I never miss a booking.
22. As an artist, I want leads fully qualified (placement, size, style, reference, budget) before I see them, so I spend zero time on discovery.
23. As an artist, I want my own pricing table (placement × body zone × duration) used for every quote, so quotes are always correct.
24. As an artist, I want to define which placements I don't do (nao_faco), so incompatible leads are declined automatically.
25. As an artist, I want to set a negotiation floor (percentage of table price), so the assistant never discounts too far.
26. As an artist, I want the assistant to request Instagram posts as contrapartida when negotiating discounts, so I get free marketing from every discounted close.
27. As an artist, I want pricing to creep up automatically on frequently-booked placements, so my rates rise with demand without manual intervention.
28. As an artist, I want to confirm deposit receipts myself, so I stay in control of the money flow.
29. As an artist, I want the assistant to propose 2–3 concrete slot options from my calendar when booking, so the lead picks a slot that actually works.
30. As an artist, I want my calendar slots automatically marked as booked when a lead confirms, so I never double-book.
31. As an artist, I want to see a full lead profile (qualification, price, reference pics, conversation summary) when the assistant hands off to me, so I can pick up the conversation immediately.
32. As an artist, I want leads to appear in my existing Notion CRM pipeline as they progress, so I use the tool I already know.
33. As an artist, I want the assistant to never expose my phone number to leads, so my privacy is protected.
34. As an artist, I want the assistant to handle abusive or spam messages silently, so I never see them.
35. As an artist, I want the assistant to respect my working hours and send a closed-studio message after hours, so work doesn't bleed into my personal life.
36. As an artist, I want to use my existing WhatsApp Business number (not a new one), so leads don't see a different number.
37. As an artist, I want the assistant paired to a dedicated business number only (never my personal WhatsApp), so a ban never touches my personal contacts.
38. As an artist, I want to configure my specialties, pricing, and calendar through a natural conversation with Beatriz during setup, so onboarding feels easy.

### VAIF Partner

39. As the VAIF partner, I want to onboard a new artist by filling a short form (name, number, specialties, pricing pre-fill), so I can set up an artist in minutes without SSH.
40. As the VAIF partner, I want stub creation to automatically provision a WAHA session and generate the onboarding QR link, so there's no manual server work.
41. As the VAIF partner, I want to send the artist their onboarding link myself (copy-paste), so I control the introduction and timing.
42. As the VAIF partner, I want Beatriz to collect the artist's full pricing table and calendar during a setup conversation, so I never fill a spreadsheet.
43. As the VAIF partner, I want to pre-fill whatever artist configuration I already know during stub creation, so the setup conversation is shorter.
44. As the VAIF partner, I want to suspend or offboard an artist when needed, so I can manage the client portfolio.
45. As the VAIF partner, I want all artists to run from a single n8n workflow, so I deploy changes once and they apply to everyone.
46. As the VAIF partner, I want a $100/month hard cap on LLM costs, so a runaway loop or spam flood can't burn more than that.
47. As the VAIF partner, I want the agent runtime to cost $0 in transport at the current 6-artist scale, so there's no cost barrier to proving the product.
48. As the VAIF partner, I want to test Beatriz's behavior on Telegram before going live on WhatsApp, so I can iterate rapidly without touching production.
49. As the VAIF partner, I want workflow changes versioned in git, so I can roll back if something breaks.

## Implementation Decisions

### Runtime

- Beatriz runs entirely in n8n — a single shared workflow, no custom code service. The webhook trigger receives WAHA messages, a Postgres node resolves `artist_id` from the session slug, and the LLM node (GPT-4o-mini via OpenRouter) generates replies using the system prompt. Postgres Chat Memory stores conversation transcripts keyed by session.
- One n8n workflow serves all artists. At workflow start, the WAHA session slug maps to `artist_id` via `artists.wa_session_slug`. Row-level security in Postgres enforces data isolation at the database level.
- The `artist_id` to session mapping is slug-based (human-readable, e.g. `bruno-tattoo`), with a `wa_session_slug TEXT UNIQUE` column on the `artists` table.
- LLM model: GPT-4o-mini via OpenRouter. OpenRouter handles provider fallback. Brazilian Portuguese is handled natively.
- Postgres Chat Memory (n8n native node) auto-manages context window trimming — the LLM receives the last N messages plus a summary of earlier conversation facts, keeping token costs flat.
- Workflows are exported as JSON from n8n to `packages/flows/`, committed to git manually for versioning.

### Transport

- V1: WAHA self-hosted on the existing Coolify server, GOWS engine (Go WebSocket, browserless). $0 transport cost.
- One WAHA session per artist, each mapped to the artist's dedicated WhatsApp Business number. No artist's personal number is paired to WAHA.
- Switch trigger to official Cloud API: first account-level action (ban/suspension) OR 30 artists on the platform — whichever fires first. New onboardings go straight to Cloud API after the switch.
- Cloud API bridge: Meta Partner registration starts at 10 artists (Business verification, App Review, Embedded Signup v4). Runs on Meta time (weeks to months), so started early for zero dead air.
- Migration path: coexistence onboarding for artists on separate business numbers (Embedded Signup with Business-app-user flow, 180-day chat history syncs from WhatsApp Business app).

### Database (Postgres / Supabase)

The operational CRM Beatriz writes to, using Postgres with row-level security for multi-tenancy. The artist's Notion CRM is a downstream sync — Beatriz never writes to Notion directly.

- **leads**: Flat table — contact info, qualification fields (placement, body_zone, style, primeira_tatuagem, significado, reference_pics), pricing fields (table_price, negotiated_price, discount_percent, contrapartida), booking fields (booked_date, session_duration_min, buffer_min), deposit fields (deposit_amount, deposit_status), pipeline_status, handoff_reason, timestamps. Indexed by `(artist_id, telefone)` and `(artist_id, pipeline_status)`.
- **artists**: Per-artist configuration — nome, specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key, instagram_handle, working_hours (JSONB), wa_session_slug, status (enum: stub/onboarding/live/suspended/offboarded), onboarding_token, whatsapp_number. RLS: `artist_self_only` policy.
- **pricing**: Placement × body-zone pricing per artist — placement, body_zone (pequeno/médio/grande/fechamento), table_price, session_duration_min, buffer_min, creeps counter. Unique constraint on `(artist_id, placement, body_zone)`.
- **calendar**: Artist availability slots — start_at, end_at, type (available/blocked/booked), lead_id reference. Indexed by `(artist_id, start_at, end_at)`.
- **events**: Append-only immutable event log — every state transition, quote, booking, and handoff. Fields: lead_id, artist_id, event_type, payload (JSONB), created_at. Used for LLM context injection (last N events per lead) and debugging.

Pipeline states: `novo → qualificando → orcamento_enviado → aguardando_deposito → agendado → fechado | aguardando_artista | perdido`.

Deposit timeout rule: if `aguardando_confirmacao` exceeds 48h, Beatriz sends one follow-up. After 72h total with no confirmation: `pipeline_status = 'perdido'`.

### Conversation Contract

Beatriz follows the artist's closing checklist as a 7-phase pipeline, never skipping phases:

1. **Greeting** — Respond in under 1 minute. Present herself. Ask name if not visible.
2. **Discovery** — First tattoo? Placement? Body zone (inferred, no cm). Style. Reference pictures. Meaning or aesthetics? One question at a time. Never re-ask answered questions.
3. **Value-building** — Compliment the reference. Connect to artist specialty. Project the result.
4. **Process explanation** — Creative process, art approval, body fit.
5. **Doubt-clearing** — "Antes de te passar os valores, ficou alguma dúvida?" Wait for confirmation. **Price is never presented before this phase completes.**
6. **Pricing** — Present table price (cash + installment up to 6x). Then stop talking: "Como fica para você?" and wait.
7. **Close** — Accept: PIX deposit → propose slots from calendar → book → confirm. Hesitate: discover reason → "Qual valor você imaginava investir?" → negotiate to floor with contrapartida (Instagram post + fechar agora) → handoff if below floor.

Tone rules (enforced by system prompt backstop): natural Brazilian Portuguese, warm, confident. Never say "talvez/pode ser/depende/quem sabe/tanto faz." Never send long text blocks. Always drive toward a decision. Use the lead's name throughout.

**Handoff triggers** (immediate, no negotiation):
- Cover-up keyword detected ("cobrir", "cobertura", "tatuagem por cima")
- Below-floor counter-offer (lead offers less than artist's floor_pct of table price)
- Second audio message from lead (first gets "me manda por texto")
- Second vague/incomplete response after two clarifying attempts
- Lead explicitly asks to speak to the artist
- Returning client detected (has prior tattoos with the artist)

**Other behaviors**: Style mismatch → graceful decline + style-conversion suggestion. "Não faço" placement → categorical decline, no handoff. Off-hours → closed-studio message with next business day/time. Abuse/troll → single farewell message, then silent.

**Fallback**: when confidence is low on any inference, Beatriz makes a best guess with explicit restatement ("Entendi que você quer [X] no [Y], estilo [Z], tamanho [W] — é isso mesmo?"). Yes → continue. No → one clarifying question. Still unclear → handoff.

### Artist Onboarding

- Artist lifecycle: `stub → onboarding → live → suspended → offboarded`.
- Stub creation: VAIF partner fills an n8n Form (name, WhatsApp Business number, plus optional pre-fill for specialties, nao_faco, floor_pct, deposit_type/value, pix_key, instagram_handle). Behind the form, n8n creates the artist row in Postgres, provisions a WAHA session, generates a one-use onboarding token, and returns a link.
- Partner copies the onboarding link and sends it to the artist via their own WhatsApp. Artist opens the link on the landing page, scans the QR code (WhatsApp Web linking), and the session goes live.
- Beatriz setup conversation: when `status = 'onboarding'` and the artist sends their first message, Beatriz enters setup mode. She dynamically asks for any NULL fields not filled by the partner, walks through the pricing table placement by placement, and collects calendar availability. On completion, transitions to `live`.
- Self-serve portal: a thin PHP page at `vaif.com.br/onboard/<token>`, built after the prototype phase validates Beatriz's behavior. Replaces the partner's copy-paste link delivery with a direct artist experience.
- Connection recovery: if a WAHA session disconnects, the artist transitions to `suspended`. Partner re-issues the onboarding link (same slug, new QR). Artist re-scans. No data loss.

### Notion Sync

A downstream pipeline mirrors every pipeline state change from Postgres to the artist's Notion workspace. Triggers: lead created, qualification updated, quote sent, handoff triggered, deposit confirmed + slot booked, fechado, perdido. The pipeline runs inside the same n8n workflow as a post-write step. Volume: ~36–72 page updates/day at 6 artists, well within Notion's 3 rps limit. Notion API is free.

### Pricing Model

- Three axes: placement × body-zone (4 tiers) × session duration. Per artist, configured during setup.
- Quote always presented as cash + installment up to 6x.
- Dynamic price creep: each time a lead closes at a given placement × body-zone cell, the listed price auto-nudges up by a small increment. Artist-configurable toggle per cell.
- Floor: per-artist percentage of table price (e.g. 80%). Beatriz negotiates down to this floor only with contrapartida. Below floor → immediate handoff.

### Cost Model

| Stage | Artists | Transport | LLM cost | Total/month |
|---|---|---|---|---|
| V1 (now) | 6 | $0 (WAHA) | ~$5 | ~$5 |
| Scale (6 mo) | 10–20 | $0 (WAHA) | ~$8–17 | ~$8–17 |
| Post-switch | 30+ | ~$1.20–2.45/artist (Cloud API) | ~$25 | ~$61–98 |

Hard cap: $100/month on OpenRouter API key, configured as an airbag.

### Security and Multi-Tenancy

- Every Postgres table carries `artist_id` FK. Row-level security enforced: every query is scoped to `current_setting('app.artist_id')`.
- Postgres Chat Memory keyed by WAHA session slug — no cross-artist transcript mixing.
- WAHA session isolation: one session = one WhatsApp Business number, one artist.
- Deposit handling: artist manually confirms PIX receipt (human gate on money). No automated payment verification in v1.
- No hardcoded API keys or secrets in workflow code — credentials managed in n8n's credential store.

## Testing Decisions

### What makes a good test

Tests assert external behavior, not implementation details. The system's only I/O boundaries are:
1. The HTTP webhook that receives WAHA message payloads and returns reply text
2. The Postgres database that stores lead profiles, pipeline state, and artist configuration

Tests inject synthetic message sequences at the webhook, capture the reply text, and query the database for expected state changes. No mocking of the LLM — the same GPT-4o-mini model is used in tests as in production.

### Seams

- **Webhook endpoint** (primary test boundary): Send message payloads in, capture reply payloads out. Assert replied text matches the conversation contract (correct phase progression, correct pipeline state transition, correct handoff trigger behavior, correct tone rules, forbidden words not present).
- **Postgres database** (verification boundary): After messages flow through, assert the correct rows changed — new lead created, qualification fields populated, pipeline status updated, events logged, pricing queried correctly, booking slots created.

### Test assets

- **Conversation fixtures** (10 scripts at `packages/flows/test/conversation-fixtures.md`): Cover ideal flow, negotiation, below-floor handoff, cover-up handoff, vague lead handoff, audio handoff, style mismatch, off-hours, abuse/troll, artist direct request. Each is a message sequence with expected checkpoints.
- **Eval set** (future): 10–15 JSON fixture files with message transcripts and expected state assertions (pipeline, placement, price, etc.). Run against the workflow with mocked WAHA/CRM outputs for automated regression.

### Prior art

The Telegram testbed (`packages/flows/beatriz-telegram/`) already functions as a manual testing harness — fake leads message the bot, human QA inspects Beatriz's replies. The webhook-based testing strategy formalizes this into automated assertions at the same boundary, without changing how the system works.

## Out of Scope

- **Self-built CRM product**: `packages/crm/` exists as a directory with a Postgres schema. Not built as part of this spec — separate wayfinding map, separate timeline. Artists use Notion as their CRM until the self-built CRM ships.
- **Meta Business verification**: Cloud API bridge registration (Business verification, App Review, Embedded Signup v4) starts at 10 artists per the transport decision. The process itself (Meta's forms and approvals) is outside the agent system's scope — it's a business-side task.
- **Lead-source attribution**: The `leads.lead_source` column is a placeholder. Attribution mechanism (which ad/campaign brought this lead) is TBD.
- **Multimodal image analysis**: v1 handles cover-up detection and size inference via text context and keywords. Multimodal analysis of reference pictures (for style detection, size estimation, cover-up detection from image content) is deferred to v2.
- **Automated PIX confirmation**: Deposit confirmation is manual (artist marks it received). Auto-polling bank APIs for PIX receipt is a v2 enhancement.
- **Per-artist LLM budget**: A global $100/month OpenRouter cap exists. Per-artist budgets are v2.
- **Notion migration for live artists**: Artists stay on their Notion template until the self-built CRM ships. No migration in this map.
- **Support for personal WhatsApp numbers**: Only dedicated WhatsApp Business numbers. No artist's personal number is paired to the agent.
- **Pricing/packaging of the agent product**: Business decision, not a technical spec concern.

## Further Notes

- All conversations are in Brazilian Portuguese. The system prompt is written in Portuguese. English is used only for specs, issues, and code documentation.
- The 10 conversation fixtures serve dual purpose: manual QA on the Telegram testbed today, and automated eval-set regression when the webhook testing harness is formalized.
- The dynamic price creep algorithm (per-cell nudge after each close) is stored in the `creeps` column but the nudge logic itself is a workflow concern, not defined here.
- The Notion sync pipeline is triggered from within the same n8n workflow after each CRM write, using per-artist Notion API tokens. The pipeline is a task ticket downstream of this spec.
- The self-serve onboarding portal (`vaif.com.br/onboard/<token>`) is phased — build after the Telegram prototype validates Beatriz's behavior and before hitting 10 artists.
- Calendar booking flow: lead picks from 2–3 proposed slots → slot is penciled (type=booked, lead_id set) → deposit confirmed by artist → booking is finalized. If deposit times out (72h), slot is released.

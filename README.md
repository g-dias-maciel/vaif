# VAIF

VAIF is a Brazilian growth agency that works exclusively with tattoo studios. This
monorepo contains every VAIF product:

- the public landing page and lead funnel (`vaif.com.br`);
- **Beatriz**, the AI SDR agent that qualifies and closes WhatsApp leads;
- the artist-facing **CRM**;
- the self-serve artist portals (onboarding, agenda, promotions);
- the n8n workflows, database schema, and course material that support them.

Everything is written for the Brazilian market: product UI and conversations are
**pt-BR**; code, specs, and this README are in English.

---

## 1. Repository map

```
packages/
  lp/          Public site + calculator funnel + blog + artist pages + portals
  flows/       n8n workflow definitions, Beatriz prompts, tests, deploy scripts
  crm/         Laravel CRM + Supabase/Postgres schema and seed data
  sdr-admin/   Standalone beta bundle for /onboard, /agenda and /promo
members/       Course scripts, decks, handouts and HTML prototypes
vaif-lp/       Legacy snapshot of the old landing page (not deployed)
docs/          Agent tooling docs and ADRs
.scratch/      Wayfinding maps, research and planning notes
.github/       CI workflows for the LP and flows packages
```

> `vaif-lp/` is a historical copy. The deployed landing page is
> `packages/lp/`. Do not edit `vaif-lp/` unless you are intentionally working on
> the old snapshot.

---

## 2. Systems at a glance

| System | Path | Stack | Main entry point | Data store |
|---|---|---|---|---|
| Landing page / funnel | `packages/lp` | PHP 8.2, Nginx, vanilla JS | `/` | MySQL (`leads`, `artists`, `intake_tokens`) |
| SDR agent (Beatriz) | `packages/flows` | n8n, JavaScript, Postgres | n8n webhook `/webhook/waha-webhook` | Supabase/Postgres |
| CRM | `packages/crm/app` | Laravel 10, PHP 8.1+ | `/login` → `/` | Supabase/Postgres |
| Beta admin bundle | `packages/sdr-admin` | PHP 8.3, Nginx | `/onboard/<token>`, `/agenda/<token>`, `/promo/<token>` | via n8n webhooks |
| Member area content | `members` | Static Markdown/PDF/HTML | external `Membros` link | none |

### Two data planes

This is important when debugging:

1. **Public funnel data** lives in a **MySQL** database used by `packages/lp`.
   Calculator and diagnostic leads are inserted into its `leads` table and
   forwarded to n8n webhooks.
2. **SDR + CRM data** lives in a **Supabase/Postgres** database. Beatriz writes
   `leads`, `calendar`, `events`, `pricing`, promotions and chat ownership
   there; the Laravel CRM reads and mutates the same tables. A `leads` row is
   both the person and their tattoo project — there is no separate `tattoos`
   table.

The two `leads` tables are different schemas in different databases. A
calculator lead is not automatically an SDR lead.

---

## 3. How the pieces fit together

```
Meta / Google Ads
Instagram / WhatsApp ──► WAHA ──► n8n "Beatriz WhatsApp Agent"
                                      │
                                      ├─► Postgres: leads, calendar, events
                                      ├─► Telegram: handoff + deposit alerts
                                      │
                                      ▼
                              Laravel CRM (artist + staff)

vaif.com.br/calculadora ──► MySQL leads ──► n8n lead / calendar webhooks
vaif.com.br (diagnostic form) ──► MySQL leads ──► n8n "Diagnóstico Gratuito"
                                                  └─► Telegram approval ──► e-mail

n8n "Artist Onboarding Form" ──► Postgres artists + pricing + WAHA session
                                      │
                                      ├─► /onboard/<token>  (scan QR)
                                      └─► /agenda/<token>   (availability + customers)

/artistas-admin/<token> ──► mint invite token ──► /novo-artista/<token>
                                      └─► draft artist page ──► publish ──► /artists/<slug>
```

---

## 4. Quick route index

### 4.1 Public site (`packages/lp`)

| Method | Route | What it does |
|---|---|---|
| `GET` | `/` | Main landing page: hero, pillars, AI SDR demo, services, diagnostic form |
| `GET` | `/calculadora` or `/calculadora/` | "Lucro Oculto" calculator funnel |
| `GET` | `/blog` | Blog listing |
| `GET` | `/blog/<slug>` | Single blog post |
| `GET` | `/artists/<slug>` | Public artist landing page |
| `GET` | `/artists/<slug>?preview=<token>` | Preview a draft artist page |
| `GET` | `/onboard/<token>` | Artist WhatsApp onboarding (QR connect) |
| `GET` | `/onboard/sucesso?token=<token>` | Onboarding success page |
| `GET` | `/agenda/<token>` | Artist agenda: availability, bookings, blocks, customers |
| `GET` | `/novo-artista/<token>` | Invite-only artist intake form |
| `GET` | `/artistas-admin/<token>` | Artist review/publish panel |
| `GET` | `/artistas-admin/<token>/<id>` | Edit one artist |
| `GET` | `/sitemap.php` | XML sitemap (blog + published artists) |
| `GET` | `/robots.txt` | Static robots file |

### 4.2 Public APIs (`packages/lp/api`)

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/leads/submit.php` | none | Save calculator lead to MySQL + forward to n8n |
| `GET` | `/api/leads/get_horarios.php` | none | Return occupied calculator calendar slots |
| `POST` | `/api/leads/update_agendamento.php` | none | Save the chosen calculator slot + notify n8n |
| `POST` | `/api/leads/diagnostico.php` | none | Save free-diagnostic lead + trigger n8n e-mail flow |
| `POST` | `/api/artists/create.php` | `X-API-Key: <API_CREATE_KEY>` | Create a config-file artist page (legacy/n8n) |
| `POST` | `/api/artists/intake.php` | invite token (create) / admin token (update) | Create or update a DB-backed artist + media |

### 4.3 CRM (`packages/crm/app`)

| Method | Route | Access | Purpose |
|---|---|---|---|
| `GET` | `/login` | guest | Login form |
| `POST` | `/login` | guest | Authenticate |
| `POST` | `/logout` | auth | End session |
| `GET` | `/` | auth | Dashboard |
| `GET` | `/analytics` | artist/staff + artist context | Funnel, revenue and pipeline analytics |
| `GET` | `/bookings` | artist/staff + artist context | Bookings and blocked periods |
| `POST` | `/bookings/block` | artist/staff + artist context | Block a date/time range |
| `GET` | `/config` | artist/staff + artist context | WhatsApp, agent and pricing settings |
| `POST` | `/config/toggle` | artist/staff + artist context | Pause/reactivate Beatriz |
| `POST` | `/config/waha/connect` | artist/staff + artist context | Create WAHA session / show QR |
| `POST` | `/config/waha/restart` | artist/staff + artist context | Restart WAHA session |
| `POST` | `/pricing/bulk` | artist/staff + artist context | Save a region's price matrix |
| `POST` | `/pricing/add-location` | artist/staff + artist context | Add a custom sub-location |
| `POST` | `/pricing/add-size` | artist/staff + artist context | Add a custom size |
| `GET` | `/leads/{lead}` | artist/staff | Lead + project detail, bookings, notes |
| `POST` | `/leads/{lead}/notes` | artist/staff | Add an internal note |
| `POST` | `/leads/{lead}/lost` | artist/staff | Mark a project lost |
| `GET` | `/tattoos/{tattoo}` | artist/staff | 301 → `/leads/{tattoo}` (legacy link) |
| `POST` | `/bookings/{slot}/unblock` | artist/staff | Remove a blocked period |
| `POST` | `/bookings/{slot}/confirm-deposit` | artist/staff | Mark the sinal as received |
| `POST` | `/bookings/{slot}/cancel` | artist/staff | Cancel booking and return to pipeline |
| `GET` | `/bookings/{slot}/reschedule` | artist/staff | Reschedule form with free slots |
| `POST` | `/bookings/{slot}/reschedule` | artist/staff | Perform the reschedule |
| `GET` | `/artists` | staff | All artists |
| `GET` | `/artists/create` | staff | Create artist form |
| `POST` | `/artists` | staff | Create artist + CRM login |
| `GET` | `/artists/{artist}/select` | staff | Select an artist context |
| `GET` | `/artists/{artist}` | staff | Artist pipeline + bookings |
| `POST` | `/artists/{artist}/toggle` | staff | Pause/reactivate that artist's agent |
| `POST` | `/api/onboard/login` | `ONBOARD_WEBHOOK_TOKEN` | Provision a CRM login from n8n |
| `GET` | `/api/user` | Sanctum | Current API user |

### 4.4 n8n webhooks (`packages/flows`)

All paths below are relative to `https://n8n.vaif.com.br`.

| Method | Route | Workflow | Purpose |
|---|---|---|---|
| `POST` | `/webhook/waha-webhook` | Beatriz WhatsApp Agent | Inbound WAHA messages/presence |
| `POST` | `/webhook/onboard-api` | Artist Onboard Webhook | `validate`, `status`, `consume` QR onboarding |
| `POST` | `/webhook/calendar` | Artist Calendar Webhook | `list`, `block`, `unblock`, `suspend`, `resume`, `customers`, `import_customer`, `return_chat` |
| `POST` | `/webhook/promotions` | Artist Promotions Webhook | `list_artists`, `list_promotions`, `create`, `activate`, `pause`, `end` |
| `POST` | `/webhook/diagnostico` | Diagnóstico Gratuito | Receive diagnostic form, Telegram approval, send e-mail |
| `POST` | `/webhook/reset-leads` | Admin Reset Leads | Destructive reset guarded by a confirm flag |
| `POST` | `/webhook/db-migration` | DB Migration | Idempotent Postgres schema/function migration |
| Form | `https://n8n.vaif.com.br/form/onboard` | Artist Onboarding Form | Create artist, pricing and WAHA session |

Telegram-triggered workflows (`Beatriz Telegram + Postgres`, `Beatriz Ops
Callback`) have no public HTTP route.

Notion is used by VAIF for **internal** operations (invoicing, contracts,
onboarding folders), not for Client data. The CRM no longer mirrors Artists,
Leads or Projects to Notion: the `notion_sync_outbox` table, the `notion_*`
columns and the Notion Sync workflows were removed (ADR-0002). Do not add a
CRM→Notion sync back; the CRM is the only surface for Client data.

---

## 5. Landing page and public site (`packages/lp`)

A plain PHP 8.2 + Nginx application. No framework, no build step. All pages are
server-rendered PHP with vanilla CSS/JS.

### 5.1 Main landing page (`/`)

`index.php` is the agency storefront. Sections:

- **Header/nav** — Pilares, Atendente Virtual, Serviços, Aplicar.
- **Hero** — headline, CTAs, animated WhatsApp chat mockup.
- **Three pillars** — funil de alto padrão, atendimento 24h, escala sem ansiedade.
- **Client marquee** — Instagram links for Jhonatan Masters, Rodrigo Silva,
  Sergio Moraes, Kleber Rocker, Bueno Tattoo and Dinho Tattoo.
- **AI SDR demo** — interactive chat simulator (`js/chat.js`) plus an animated
  agenda dashboard (`js/chat-animation.js`) showing bookings, revenue and slots.
- **Services grid** — six service cards (ads, SDR + CRM, scripts, booking
  automation, landing pages, consulting).
- **Calculator teaser** — link to `/calculadora/`.
- **Free diagnostic form** — name, studio, WhatsApp, Instagram, e-mail, monthly
  revenue, average ticket. Submits to `/api/leads/diagnostico.php`.
- **Footer** — navigation, Instagram, e-mail, legal line.

Analytics: Matomo (`analytics.vaif.com.br`, site ID `1`) and Facebook Pixel
(`752550821217294`). Events include `PageView`, `Lead`, `Schedule`,
`InitiateCheckout` and `ScheduleSkippedToWhatsapp`.

### 5.2 Calculator funnel (`/calculadora/`)

`calculadora.php` + `js/calculator.js` + `js/calculadora-page.js`.

**Step 1 — calculator form.** Four inputs: monthly revenue, average ticket,
sessions per month, daily hours spent negotiating on WhatsApp.

**Step 2 — result.** The calculation is:

```
valor_hora        = faturamento / (sessoes * 8)
horas_secretario  = horas_admin * 26
prejuizo_mensal   = horas_secretario * valor_hora
potencial_lucro   = faturamento + (prejuizo_mensal * 0.7)
```

The page shows the monthly loss, the 70%-recovery potential, social proof, a
testimonial video, and the lead form (name, WhatsApp, Instagram).

**Step 3 — conditional routing after lead submit.**

| Condition | Experience |
|---|---|
| `faturamento >= 4000` | Native calendar with 2-day window, slots at `10:00`, `14:00`, `17:00` (`America/Sao_Paulo`). Occupied slots come from `/api/leads/get_horarios.php`; the window scans forward up to 60 days. Confirming calls `/api/leads/update_agendamento.php`. |
| `faturamento < 4000` | E-book offer with coupon `TATTOO10K` and a link to `https://ebook.vaif.com.br/tatuador-10k`. |

**Step 4 — success.** A personalised confirmation screen with the booked date,
the specialist card, a warm-up video and a WhatsApp CTA.

The lead is saved by `POST /api/leads/submit.php` and forwarded to
`N8N_LEAD_WEBHOOK_URL`. Booking confirmation is forwarded to
`N8N_CALENDAR_WEBHOOK_URL`.

### 5.3 Free diagnostic form

The form at `/#aplicar` submits to `POST /api/leads/diagnostico.php` with:

```json
{
  "nome": "...",
  "studio": "...",
  "whatsapp": "...",
  "email": "...",
  "instagram": "...",
  "faturamento": 15000,
  "ticket": 3000,
  "origem": "formulario_final"
}
```

The endpoint writes the lead to MySQL (non-blocking) and forwards it to
`N8N_DIAGNOSTICO_WEBHOOK_URL` (`/webhook/diagnostico`). That n8n workflow:

1. responds `{ success: true }` immediately;
2. builds the diagnostic HTML and a Telegram summary;
3. sends an approval card to the VAIF ops group;
4. on approval, sends the diagnostic by SMTP e-mail.

### 5.4 Blog (`/blog`)

Markdown-driven, no CMS. Posts live in `content/blog/*.md` with frontmatter:

```yaml
---
title: ...
date: 2026-01-15
slug: ...
description: ...
author: ...
featured_image: /img/blog/...
tags: automacao, tatuagem, negocios
category: Estratégia
draft: false
---
```

- `/blog` lists non-draft posts.
- `/blog/<slug>` renders one post, with Markdown, canonical/OG/Twitter metadata
  and a `BlogPosting` JSON-LD block.
- Draft posts are excluded from the listing, direct URLs and the sitemap.
- Posts are included in `/sitemap.php`.

### 5.5 Artist landing pages (`/artists/<slug>`)

One page per artist, generated from either:

- a legacy PHP config file: `artists/config/<slug>.php`, or
- the database-backed `artists` table (created through the intake form).

Sections: hero, portfolio (lightbox), before/after slider, about + stats,
booking CTA, testimonials, Instagram feed, FAQ, location/map, closing CTA, and
a mobile sticky WhatsApp button.

SEO: canonical URL, Open Graph/Twitter tags, `LocalBusiness` + `Person` +
`FAQPage` JSON-LD, and inclusion in the sitemap when published.

Drafts are visible only through:

```
/artists/<slug>?preview=<preview_token>
```

### 5.6 Artist intake and review

**Artist review panel** — `/artistas-admin/<token>` (and `/<id>` to edit).

Token: `ARTISTS_ADMIN_TOKEN`, falling back to `API_CREATE_KEY`. Features:

- list DB-backed artists with draft/published badges;
- preview a draft;
- edit all content through the shared intake form;
- publish / unpublish;
- delete an artist and its media directory;
- mint single-use invite tokens for `/novo-artista/<token>`;
- revoke unused invite tokens.

**Artist intake form** — `/novo-artista/<token>`.

Invite-only and single-use. The artist fills identity, styles, bio, portfolio,
before/after pairs, stats, testimonials, FAQ, location, opening hours and social
links. Photos are uploaded and stored under `artists/<slug>/media/`. The
submission creates a **draft**, returns a preview URL, and consumes the invite
token.

**Endpoints**

- `POST /api/artists/intake.php` — multipart form.
  - `mode=create` requires `intake_token`; creates a draft.
  - `mode=update` requires `admin_token` (or `X-Admin-Token`) and `id`.
- `POST /api/artists/create.php` — legacy JSON endpoint for n8n. Requires
  `X-API-Key: <API_CREATE_KEY>`. Writes a PHP config file plus media directory.

### 5.7 Artist onboarding portal (`/onboard/<token>`)

Self-serve WhatsApp connection:

1. validates the artist's `onboarding_token` through n8n
   (`/webhook/onboard-api`, action `validate`);
2. shows the WAHA QR code;
3. polls status (`action=status`) and refreshes the QR when it expires;
4. on connection, consumes the token (`action=consume`) and redirects to
   `/onboard/sucesso?token=<token>`;
5. the success page links to `/agenda/<token>`.

Requires `N8N_ONBOARD_WEBHOOK_URL`. Without it the page shows "Em manutenção".

### 5.8 Artist agenda portal (`/agenda/<token>`)

Self-serve availability and customer management:

- shows whether Beatriz is active or paused;
- lists upcoming booked tattoos with client name and placement;
- blocks a date/time range (day off, personal appointment);
- lists and unblocks existing blocks;
- lists customers with **Novo** / **Recorrente** badges and chat ownership;
- lets the artist return a chat to Beatriz;
- lets the artist mark an existing customer as returning.

All actions post to `N8N_AGENDA_WEBHOOK_URL` (`/webhook/calendar`):

| Action | Payload |
|---|---|
| `list` | `{ token, action: "list" }` |
| `block` | `{ token, action: "block", start_at, end_at }` |
| `unblock` | `{ token, action: "unblock", block_id }` |
| `suspend` / `resume` | `{ token, action }` |
| `customers` | `{ token, action: "customers" }` |
| `import_customer` | `{ token, action, telefone, nome }` |
| `return_chat` | `{ token, action, chat_id }` |

### 5.9 LP environment variables

| Variable | Required | Used by |
|---|---|---|
| `DB_HOST` | yes | all LP DB access |
| `DB_NAME` | yes | all LP DB access |
| `DB_USER` | yes | all LP DB access |
| `DB_PASSWORD` | yes | all LP DB access |
| `N8N_LEAD_WEBHOOK_URL` | optional | calculator lead submit |
| `N8N_CALENDAR_WEBHOOK_URL` | optional | calculator booking confirm |
| `N8N_AGENDA_WEBHOOK_URL` | for `/agenda/*` | agenda portal |
| `N8N_ONBOARD_WEBHOOK_URL` | for `/onboard/*` | onboarding portal |
| `N8N_DIAGNOSTICO_WEBHOOK_URL` | for diagnostic form | `/api/leads/diagnostico.php` |
| `ARTISTS_ADMIN_TOKEN` | for admin panel | `/artistas-admin/*` |
| `API_CREATE_KEY` | for artist API | `/api/artists/create.php` |

### 5.10 Run the LP locally

From the repo root:

```bash
php -S localhost:8000 -t packages/lp packages/lp/router.php
```

`router.php` mirrors the production Nginx rewrites for `/blog`, `/artists`,
`/onboard`, `/agenda`, `/novo-artista`, `/artistas-admin` and `/calculadora`.

Run the LP tests:

```bash
php -S localhost:8000 -t packages/lp packages/lp/router.php > /tmp/lp.log 2>&1 &
php packages/lp/tests/acceptance_test.php
php packages/lp/tests/routing_acceptance_test.php
php packages/lp/tests/agenda_acceptance_test.php
php packages/lp/tests/onboard_acceptance_test.php
php packages/lp/tests/artist_intake_acceptance_test.php
```

### 5.11 Deploy the LP

See `packages/lp/DEPLOY-COOLIFY.md`. Short version:

- Coolify app → build type **Dockerfile**;
- base directory `/packages/lp`;
- exposed port `80`;
- set the environment variables above.

The Docker image runs Nginx + PHP-FPM in one container and uses
`packages/lp/deploy/nginx.conf`.

---

## 6. SDR agent — Beatriz (`packages/flows`)

n8n is the runtime. Workflow JSON lives in `definitions/`; prompts and
JavaScript used by the workflows are versioned alongside it.

### 6.1 What Beatriz does

Beatriz is a named AI assistant that works an artist's inbound WhatsApp leads
and tries to close them into a booked tattoo session. The deployed workflow is
**Beatriz WhatsApp Agent** (`beatriz-whatsapp-agent.json`).

Core behavior:

- **Multi-tenant routing** — WAHA sends the session slug; n8n resolves the
  artist, timezone, pricing, deposit rule, working hours and promotion.
- **Conversation contract** — 10 phases: greeting → discovery → value building
  → price gate → process explanation → doubt loop → quote → negotiation →
  available dates → booking/deposit → confirmation.
- **Price gate** — Beatriz is forbidden from mentioning prices until she has
  explained the artist's creative process and the lead has explicitly said they
  have no more questions.
- **Qualification** — placement, body zone (`pequeno`/`medio`/`grande`/
  `fechamento`), style, first tattoo, meaning, tattoo type.
- **Pricing** — reads the artist's `pricing` table by placement × body zone;
  writes the quote through the `write_quote` Postgres tool.
- **Negotiation** — applies the artist's `floor_pct`; below the floor, hands off.
- **Promotions** — loads the artist's active promotion and writes a promotion
  quote when eligible.
- **Availability and booking** — `check_availability` derives 60-minute slots
  from the artist's `working_hours`; `book_slot` / `book_slot_checked` reserve
  the slot and guard against conflicts.
- **Deposit (sinal)** — requests the PIX deposit through `request_deposit`,
  using the exact amount calculated by the workflow (never by the model).
- **Handoff triggers** — cover-up/reform requests, artist requests, missing
  price rows, below-floor negotiations, deposit refusal and other exceptions.
  Handoffs notify the artist's Telegram group.
- **Chat ownership** — if the artist replies directly in WhatsApp, Beatriz
  takes the chat out of the AI window; the artist returns it from `/agenda`.
- **Returning leads** — `returning_customers` marks old clients so promotions
  and messaging can target new vs returning audiences.
- **Debounce + humanization** — buffers rapid messages, waits, shows typing,
  and adds a humanized delay before sending.
- **AI active hours** — respects the artist's `ai_active_hours`.
- **Conversation classification** — a second model call extracts fields and
  advances `pipeline_status` (`novo` → `qualificando` → `orcamento_enviado` →
  `aguardando_deposito` → `agendado`, plus `aguardando_artista`,
  `fechado`, `perdido`, `bloqueado`).
- **Audit trail** — every state transition writes an `events` row.

### 6.2 Workflows

| File | Workflow | Trigger | Purpose |
|---|---|---|---|
| `beatriz-whatsapp-agent.json` | Beatriz WhatsApp Agent | `/webhook/waha-webhook` | Production WhatsApp SDR |
| `beatriz-telegram.json` | Beatriz Telegram + Postgres | Telegram trigger | Testbed for the same conversation |
| `beatriz-ops-callback.json` | Beatriz Ops Callback | Telegram callback | Handoff/deposit buttons from Telegram |
| `artist-onboard-webhook.json` | Artist Onboard Webhook | `/webhook/onboard-api` | QR validation, status, consume |
| `artist-calendar-webhook.json` | Artist Calendar Webhook | `/webhook/calendar` | Availability, blocks, SDR status, customers |
| `artist-promotions-webhook.json` | Artist Promotions Webhook | `/webhook/promotions` | Create/activate/pause/end promotions |
| `artist-onboarding-form.json` | Artist Onboarding Form | n8n form `/form/onboard` | Create artist + pricing + WAHA session |
| `diagnostico-gratuito.json` | Diagnóstico Gratuito | `/webhook/diagnostico` | Telegram approval → e-mail diagnostic |
| `admin-reset-leads.json` | Admin Reset Leads | `/webhook/reset-leads` | Destructive reset (guarded) |
| `migration-beatriz.json` | DB Migration | `/webhook/db-migration` | Idempotent schema/functions |

### 6.3 Database contract

The Postgres schema is owned by `packages/crm/supabase/migrations` and
`seed.sql`. The main tables:

| Table | Purpose |
|---|---|
| `artists` | Tenant config: timezone, working hours, deposit, PIX, floor, session slug |
| `leads` | One person **and** their project per artist (name, phone, source, placement, style, price, deposit, pipeline status) |
| `calendar` | Booked and blocked slots |
| `events` | Audit log of every transition |
| `pricing` | Price matrix (placement × body zone × duration) |
| `promotions`, `promotion_prices`, `promotion_quotes` | Pre-approved offers |
| `returning_customers` | Old clients per artist |
| `chat_control` | Who owns a WhatsApp chat |
| `message_buffer` | Debounce state |

The accepted schema for 1,000+ Artists is in
[`docs/design/crm-data-model-v2.md`](docs/design/crm-data-model-v2.md)
(decision: [`docs/adr/0002-crm-schema-v2.md`](docs/adr/0002-crm-schema-v2.md)).
The actual deployed schema is captured in
[`packages/crm/db/baseline`](packages/crm/db/baseline), with the differences
recorded in
[`docs/design/crm-v1-production-drift.md`](docs/design/crm-v1-production-drift.md).
A future automatic-deposit product is sketched in
[`docs/ideas/signal-confirmation-platform.md`](docs/ideas/signal-confirmation-platform.md).

Key Postgres functions used by n8n: `set_artist_context`,
`resolve_artist_from_session`, `validate_onboarding_token`,
`consume_onboarding_token`, `complete_artist_onboarding`, `suspend_artist`,
`resume_artist`, `lookup_price`, `write_quote`, `request_deposit`,
`check_availability`, `book_slot`, `book_slot_checked`, `block_slot`,
`unblock_slot`, `activate_promotion`, `pause_promotion`, `end_promotion`,
`is_returning_customer`, `active_offer_for_lead`, `write_promotion_quote`,
`import_returning_customer`, `take_chat_control`, `return_chat_control`,
`chat_owner`, `confirm_deposit`.

### 6.4 Deploy and test

Validate/normalize a workflow JSON:

```bash
python3 packages/flows/definitions/build-workflow.py
```

Deploy to n8n (`N8N_API_KEY` in env or `~/.n8n-api-key`):

```bash
python3 packages/flows/scripts/deploy.py create packages/flows/definitions/beatriz-whatsapp-agent.json
python3 packages/flows/scripts/deploy.py update <workflow_id> packages/flows/definitions/beatriz-whatsapp-agent.json
python3 packages/flows/scripts/deploy.py activate <workflow_id>
```

Offline tests (used in CI):

```bash
python3 packages/flows/tests/test_system_prompt.py
node packages/flows/definitions/code/test-price-gate.js
node packages/flows/tests/conversation/run.js
```

Live-stack tests (mutate a dedicated test artist):

```bash
node packages/flows/tests/integration/calendar-agenda.js
node packages/flows/tests/integration/full-workflow.js
python3 packages/flows/tests/test_harness.py
```

### 6.5 Telegram testbed

`beatriz-telegram.json` runs the same persona against Telegram with full
Postgres persistence. Workflow ID `JyNwRrqonIEExg2m`. See
`packages/flows/beatriz-telegram/README.md`.

---

## 7. CRM (`packages/crm/app`)

A Laravel 10 application. It shares the Supabase/Postgres database with
Beatriz, so the CRM is the system of record for the artist.

### 7.1 Roles and access

- `users.role` is `artist` or `staff`.
- `users.artist_id` links an artist user to the `artists` table; staff have no
  artist and select one from `/artists`.
- Laravel native session auth; authorization in policies + `role:` middleware.
- The app connects through a service-role Postgres connection, bypassing RLS.
  RLS remains in place for the n8n/Beatriz path. See
  `docs/adr/0001-auth-model.md`.

### 7.2 Features

**Dashboard (`/`)**

- Agent pause/reactivate toggle.
- Month revenue split into **Realizado** (fechado), **Agendado** (agendado) and
  **Esperado** (active, deposit not confirmed).
- Week calendar (Mon–Sun) with booked and blocked slots; previous/next week.
- Lead pipeline kanban by `pipeline_status`, with lead name, phone, placement,
  style, price, deposit and booked date.
- Quick WhatsApp link and "Marcar perdido" action.

**Bookings (`/bookings`)**

- Upcoming / past / all filters.
- Block a full day or a time range.
- Unblock a period.
- Confirm the sinal.
- Cancel a booking (removes the slot and returns the project to
  `aguardando_deposito`).
- Reschedule to a free slot derived from the artist's working hours; the
  reschedule validates against real availability and cannot double-book.

**Analytics (`/analytics`)**

- Period filter: last 30 days, this month, last 90 days, all.
- Lead funnel: total leads, new clients, conversion rate, loss rate.
- Money from won leads: average ticket and revenue.
- Pipeline distribution.
- Lead source distribution.
- 12-month charts for conversions, conversion rate and revenue.

**Lead detail (`/leads/{lead}`)**

- Contact data, WhatsApp deep link, returning-client badge.
- Project details: placement, body zone, style, first tattoo, meaning, type,
  reference photos.
- Table price, negotiated price, discount, deposit amount/status.
- Promotion quotes, linked calendar slots and event history.
- Internal notes with author; add note.
- Mark lost.

`/tattoos/{id}` permanently redirects (301) to `/leads/{id}` for old links.

**Settings (`/config`)**

- WAHA connection status, QR code, connect/recreate session, restart session.
- Agent pause/reactivate.
- Price table by region tabs (Perna, Braço, Tronco), sub-location rows and size
  columns (Pequeno, Médio, Grande, Fechamento).
- Add custom sub-locations and custom sizes.
- Link to the external Member Area.

**Staff (`/artists`, `/artists/create`, `/artists/{artist}`)**

- List every artist with lead counts.
- Create an artist plus their CRM login and default working hours.
- Select an artist context to open that artist's CRM tabs.
- Toggle the artist's agent.
- View that artist's pipeline and upcoming bookings.

> Product intent in `CONTEXT.md` says staff browse artists **read-only**. The
> current policies grant staff the same mutations as the artist (notes, lost,
> reschedule, deposit, cancel). Treat this as a known gap when changing access.

**API**

- `POST /api/onboard/login` — token-guarded provisioning of an artist CRM login
  from n8n. Token: `ONBOARD_WEBHOOK_TOKEN` (Bearer or `token` field).
- `GET /api/user` — current Sanctum user.

### 7.3 Setup

```bash
cd packages/crm/app
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Configure the shared Postgres in `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=<supabase-host>
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=<service-role-password>

WAHA_API_URL=https://waha.vaif.com.br
WAHA_API_KEY=<waha-key>
WAHA_WEBHOOK_URL=https://n8n.vaif.com.br/webhook/waha-webhook
ONBOARD_WEBHOOK_TOKEN=<shared-secret>
MEMBER_AREA_URL=https://membros.vaif.com.br/
```

Production schema comes from `packages/crm/supabase/migrations` and
`seed.sql`. Laravel migrations also create the domain tables so the app can run
on SQLite for tests.

Seeded local users:

| E-mail | Role | Artist | Password |
|---|---|---|---|
| `admin@vaif.com.br` | staff | — | `password` |
| `bruno@vaif.com.br` | artist | Bruno | `password` |

Run the CRM tests:

```bash
cd packages/crm/app
php artisan test
```

---

## 8. Beta admin bundle (`packages/sdr-admin`)

A standalone PHP 8.3 + Nginx bundle used on staging before the production LP was
ready. It contains copies of the onboarding and agenda pages plus the promotions
panel.

### 8.1 Routes

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `GET` | `/onboard/<token>` | artist token | WhatsApp QR connection |
| `GET` | `/onboard/sucesso?token=<token>` | none | Success page |
| `GET` | `/agenda/<token>` | artist token | Availability, bookings, blocks, customers |
| `POST` | `/agenda/<token>` | artist token | `block`, `unblock`, `suspend`, `resume`, `import_customer`, `return_chat` |
| `GET` | `/promo/<admin_token>` | `VAIF_ADMIN_TOKEN` | Promotions admin panel |
| `POST` | `/promo/<admin_token>` | `VAIF_ADMIN_TOKEN` | `create`, `activate`, `pause`, `end` |

### 8.2 Promotions panel

VAIF-partner-only. One active promotion per artist. The price is a multiplier
over the artist's table, frozen at activation time.

- create: artist, name, multiplier, audience (`all` / `new` / `returning`);
- activate: freezes prices and makes the offer available to Beatriz;
- pause: temporarily disables it;
- end: closes it permanently.

### 8.3 Environment

| Variable | Purpose |
|---|---|
| `N8N_ONBOARD_WEBHOOK_URL` | `https://n8n.vaif.com.br/webhook/onboard-api` |
| `N8N_AGENDA_WEBHOOK_URL` | `https://n8n.vaif.com.br/webhook/calendar` |
| `N8N_PROMOTIONS_WEBHOOK_URL` | `https://n8n.vaif.com.br/webhook/promotions` |
| `VAIF_ADMIN_TOKEN` | Shared secret for `/promo/<token>` |

### 8.4 Notes

- `onboard/` and `agenda/` are copies of `packages/lp/{onboard,agenda}`. Keep
  them in sync.
- The checked-in `nginx.conf` routes only `/onboard` and `/agenda`. The
  `/promo/<token>` route exists in `index.php` but needs an Nginx rule
  (`^/(onboard|agenda|promo)`) in the deployment to be reachable.
- Run locally:

```bash
cd packages/sdr-admin
N8N_ONBOARD_WEBHOOK_URL=https://n8n.vaif.com.br/webhook/onboard-api \
N8N_AGENDA_WEBHOOK_URL=https://n8n.vaif.com.br/webhook/calendar \
N8N_PROMOTIONS_WEBHOOK_URL=https://n8n.vaif.com.br/webhook/promotions \
VAIF_ADMIN_TOKEN=<token> \
php -S 0.0.0.0:8000 index.php
```

---

## 9. Member area content (`members`)

Static course material for artists:

- `members/scripts/` — four lessons in Markdown and PDF:
  - Como Vender Tatuagem no WhatsApp
  - Criação de Conteúdo (Tráfego Pago e Orgânico)
  - Google Meu Negócio para Tatuadores
  - Remarketing para Tatuadores
- `members/scripts/decks/` — HTML slide decks.
- `members/scripts/handouts/` — PDF handouts.
- `members/scripts/prototypes/` — HTML layout prototypes.
- `members/presentation/` — currently empty.

The CRM links to the external member area through `MEMBER_AREA_URL`
(default `https://membros.vaif.com.br/`).

---

## 10. Data model summary

| Table | Owner | Notes |
|---|---|---|
| `artists` | LP MySQL + SDR Postgres | Two different tables: LP stores landing-page content; Postgres stores tenant config |
| `leads` | LP MySQL + SDR Postgres | Two different schemas; in Postgres one row is a person + their project |
| `calendar` | SDR Postgres | `booked` and `blocked` slots |
| `events` | SDR Postgres | Audit log |
| `pricing` | SDR Postgres | Placement × body zone × duration |
| `promotions`, `promotion_prices`, `promotion_quotes` | SDR Postgres | Pre-approved offers |
| `returning_customers` | SDR Postgres | Old-client registry |
| `chat_control` | SDR Postgres | Who answers a WhatsApp chat |
| `message_buffer` | SDR Postgres | Debounce buffer |
| `users`, `notes` | Laravel migrations | CRM identity + internal notes |
| `intake_tokens` | LP MySQL | Single-use artist invite tokens |

---

## 11. Testing and CI

| Suite | Command | CI |
|---|---|---|
| LP acceptance/routing | `php packages/lp/tests/acceptance_test.php` etc. | `.github/workflows/lp-tests.yml` |
| Flows prompt + price gate | `python3 packages/flows/tests/test_system_prompt.py`, `node .../test-price-gate.js` | `.github/workflows/flows-tests.yml` |
| Beatriz conversation suite | `node packages/flows/tests/conversation/run.js` | `.github/workflows/flows-tests.yml` |
| CRM feature tests | `cd packages/crm/app && php artisan test` | manual |
| Live n8n/Postgres integration | `node packages/flows/tests/integration/*.js` | manual |

The conversation suite runs the **actual generated workflow** with mocked
inputs, covering price gate, doubt loop, negotiation, handoff, deposit,
debounce/typing, humanized delay and notification text.

---

## 12. Deployment summary

| Component | How it is deployed |
|---|---|
| LP | Dockerfile at `packages/lp/Dockerfile`; Coolify base `/packages/lp`; Nginx + PHP-FPM; port 80 |
| sdr-admin | Dockerfile at `packages/sdr-admin/Dockerfile`; PHP-FPM on port 9000 behind Nginx |
| CRM | Dockerfile at `packages/crm/app/Dockerfile`; Coolify base `/packages/crm/app`; Nginx + PHP-FPM; port 80; needs shared Postgres |
| Flows | Import/deploy JSON to n8n with `packages/flows/scripts/deploy.py` |
| Members | Static files; serve separately or upload to the member area |

---

## 13. Known gaps and gotchas

- **Two databases.** Calculator leads (MySQL) are not SDR leads (Postgres).
- **Most admin portals depend on n8n.** If `N8N_*_WEBHOOK_URL` is unset, the
  onboarding/agenda pages show "Em manutenção" and API actions fail.
- **Tokens in URLs are secrets.** `/onboard/<token>`, `/agenda/<token>`,
  `/novo-artista/<token>`, `/artistas-admin/<token>` and `/promo/<token>` grant
  access. Treat them like passwords and rotate them when leaked.
- **Sitemap mismatch.** The sitemap is generated at `/sitemap.php`, while
  `robots.txt` advertises `https://vaif.com.br/sitemap.xml`. Add a server
  rewrite or update `robots.txt`.
- **`/promo` needs an Nginx rule** in `packages/sdr-admin/nginx.conf`.
- **Staff read-only is not enforced.** Policies currently let staff mutate
  artist data, despite the documented read-only intent.
- **`vaif-lp/` is legacy.** Use `packages/lp/` for all landing-page work.
- **`packages/sdr-admin/{onboard,agenda}` are copies.** Update
  `packages/lp/{onboard,agenda}` first, then sync.
- **Promotion price is frozen at activation.** Changing the artist's price table
  later does not change an active promotion.
- **Calculator qualification threshold is hard-coded at R$ 4,000** in
  `js/calculadora-page.js` (and mirrored in the funnel copy).

---

## 14. Where to go next

| Topic | File |
|---|---|
| Product context and vocabulary | `CONTEXT.md`, `PRODUCT.md` |
| Design system | `DESIGN.md` |
| Auth decision | `docs/adr/0001-auth-model.md` |
| LP deep dive | `packages/lp/CLAUDE.md` |
| LP deployment | `packages/lp/DEPLOY-COOLIFY.md` |
| CRM app notes | `packages/crm/app/README.md` |
| CRM deployment | `packages/crm/app/DEPLOY-COOLIFY.md` |
| Beatriz testbed | `packages/flows/beatriz-telegram/README.md` |
| Beatriz prompt | `packages/flows/prompts/beatriz-system.md` |
| Conversation tests | `packages/flows/tests/conversation/README.md` |
| Integration tests | `packages/flows/tests/integration/README.md` |
| Wayfinding maps | `.scratch/` |

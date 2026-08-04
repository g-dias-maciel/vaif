# Artist Onboarding and Tenancy

Resolved from grilling ticket 11, 2026-07-28. Built on [transport-decision.md](transport-decision.md) and [crm-write-contract.md](crm-write-contract.md).

## 1. Workflow model: one shared, session-routed

One n8n workflow handles all artists. At message receipt, the WAHA webhook delivers the session name, the workflow resolves `artist_id` via `artists.wa_session_slug`, sets `app.artist_id`, and RLS scopes everything from there.

One codebase, one prompt, one deploy surface. Isolation comes from the data layer (RLS), not the runtime. Postgres Chat Memory is keyed by session — no cross-artist transcript leaks.

## 2. WAHA session naming: slug-based

Sessions are named with a short slug derived from the artist's name (e.g., `bruno-tattoo`, `carla-ink`). Human-legible in the WAHA dashboard for debugging.

Schema additions to `artists`:

```sql
wa_session_slug TEXT UNIQUE,
status TEXT NOT NULL DEFAULT 'stub',
onboarding_token TEXT UNIQUE,
```

`wa_session_slug` maps to the WAHA session name. `onboarding_token` is a one-use token for the self-serve portal (future).

## 3. Artist lifecycle state machine

```
stub → onboarding → live → suspended → offboarded
```

| State | Meaning |
|---|---|
| `stub` | Partner created a bare artist record. No WAHA session yet, no setup conversation done. |
| `onboarding` | WAHA session created, onboarding token issued, QR delivered to artist. Beatriz waits for the artist's first message in setup mode. Pricing/calendar incomplete. |
| `live` | Setup conversation complete. Beatriz handles inbound leads from non-artist contacts. |
| `suspended` | Agent paused for this artist (payment issue, Meta action, artist request). Leads accumulate unhandled. |
| `offboarded` | Artist left VAIF. Data preserved, agent deactivated. |

Transition rules:
- `stub` → `onboarding`: partner creates WAHA session and issues onboarding link
- `onboarding` → `live`: Beatriz marks setup complete (all required pricing collected)
- `live` → `suspended`: manual (partner action, or automated on WAHA session disconnect)
- `suspended` → `live`: manual re-activation
- `live` → `offboarded`: manual, never automated
- `suspended` → `offboarded`: manual

## 4. Stub creation flow (partner-triggered)

```
Partner fills n8n Form (token-protected)
       │
       ▼
n8n webhook workflow
       │
       ├── Creates artist row in Postgres (status = 'stub')
       ├── Generates onboarding_token (one-use)
       ├── Creates WAHA session via WAHA API (slug = wa_session_slug)
       └── Returns onboarding link: https://vaif.com.br/onboard/<token>
               │
               ▼
         Partner copies link → sends to artist via their own WhatsApp
```

The partner fills everything they know:
- `nome` (required)
- WhatsApp Business number (required — needed to create the WAHA session)
- `specialties` (optional, multi-tag)
- `nao_faco` (optional, multi-tag)
- `floor_pct` (optional)
- `deposit_type` + `deposit_value` (optional)
- `pix_key` (optional)
- `instagram_handle` (optional)

## 5. Beatriz setup conversation (onboarding state)

When the artist scans the QR and sends their first message, Beatriz is in setup mode. She detects this because `artists.status = 'onboarding'`.

Her objective: fill every NULL field across `artists`, `pricing`, and `calendar`.

**Dynamic interview flow:**

1. Greeting + context ("Sou a Beatriz, assistente da VAIF. Vamos configurar seu atendimento?")
2. For each NULL field in `artists` that the partner didn't fill: ask for it conversationally.
3. For `pricing`: walk through each placement the artist offers (up to 14), for each placement ask which body zones apply, for each zone ask the price, session duration, and buffer — write a row after each answer.
4. For `calendar`: ask working days, shift blocks (manhã/tarde), block-offs.
5. Final confirmation: summarize everything, ask for corrections.
6. Transition `status` to `live`.

After setup, if a lead asks about a placement with no pricing, Beatriz says "deixa eu confirmar com o artista" and the partner fills the gap manually — not a full setup re-run.

## 6. Self-serve portal (phased, after prototype validation)

A thin PHP page on the LP at `vaif.com.br/onboard/<token>`:
- Validates the token (single-use, 24h expiry)
- Calls the n8n webhook to get the QR from WAHA
- Displays the QR with instructions ("Abra o WhatsApp Business no seu celular → Dispositivos vinculados → Escanear QR")
- Auto-refreshes the QR before it expires
- On successful scan → redirects to a "conversa iniciada" message

The portal is built after ticket 10 (Telegram prototype) validates Beatriz's behavior. The WAHA dashboard works for 6 artists today, but reconnections at scale need self-serve.

## 7. Connection recovery

WAHA sessions disconnect — phone reboot, WhatsApp logout, token expiry. The `artists.status` field handles this:

- **Detected**: n8n workflow heartbeat checks WAHA session status periodically. On disconnect for an `live` artist, auto-transition to `suspended`.
- **Recovery**: Partner re-issues onboarding link (reuses existing `wa_session_slug`, new QR from WAHA). Artist re-scans. Session resumes. No data loss — Postgres Chat Memory persists, leads table untouched.

## 8. Multi-tenancy summary

| Layer | Mechanism |
|---|---|
| WhatsApp transport | One WAHA session per artist (one session = one number) |
| n8n runtime | One shared workflow; session slug → `artist_id` lookup at entry |
| Database (Postgres) | `artist_id` FK on every table; RLS policies on all tables |
| Chat memory (Postgres Chat Memory) | Session-keyed, isolated per `wa_session_slug` |
| Sync to Notion | Per-artist Notion API token, resolved from `artists.notion_token` (future field) |

## 9. Schema updates

Add to `artists` (from [crm-write-contract.md](crm-write-contract.md)):

```sql
ALTER TABLE artists
  ADD COLUMN wa_session_slug   TEXT UNIQUE,
  ADD COLUMN status            TEXT NOT NULL DEFAULT 'stub'
    CHECK (status IN ('stub','onboarding','live','suspended','offboarded')),
  ADD COLUMN onboarding_token  TEXT UNIQUE,
  ADD COLUMN whatsapp_number   TEXT;
```

`wa_session_slug` and `whatsapp_number` are set at stub creation time once the partner enters the number. `onboarding_token` is generated once, consumed once.

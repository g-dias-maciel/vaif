# Agent↔CRM Write Contract

Resolved from grilling ticket 08, 2026-07-28. Built on [conversation-contract.md](conversation-contract.md) and [runtime-stack.md](runtime-stack.md).

## Architecture

Beatriz writes to Postgres (Supabase, n8n-native) — fast, atomic, no API cliffs. The artist's CRM (Notion today, self-built later) is a downstream sync.

```
WhatsApp → WAHA → n8n (Beatriz) → Postgres (operational)
                                       │
                                       ▼ sync layer (separate concern)
                                  Notion (artist CRM today)
                                  Self-built CRM (future)
```

This contract defines the Postgres schema and the CRUD surface Beatriz uses. The sync-to-Notion pipeline is a separate task ticket, downstream of this contract.

## Schema

### Table: `leads` (flat, single table for v1)

```sql
CREATE TABLE leads (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),

  -- Contact
  nome          TEXT,
  telefone      TEXT NOT NULL,           -- WhatsApp number, E.164-ish
  lead_source   TEXT,                    -- placeholder, fog

  -- Qualification
  placement           TEXT,              -- from artist's placement list
  body_zone           TEXT,              -- pequeno / médio / grande / fechamento
  style               TEXT,              -- matched against artist specialties
  primeira_tatuagem   BOOLEAN,
  significado         TEXT,
  reference_pics      TEXT[],            -- S3 URLs or local paths

  -- Pricing (set when Beatriz quotes)
  table_price         INTEGER,           -- R$, in cents
  negotiated_price    INTEGER,
  discount_percent    NUMERIC(5,2),
  contrapartida       TEXT DEFAULT 'Instagram post + fechando agora',

  -- Booking (set when slot is booked, after deposit confirmed)
  booked_date         TIMESTAMPTZ,
  session_duration_min INTEGER,         -- from pricing table
  buffer_min          INTEGER,

  -- Deposit
  deposit_amount      INTEGER,           -- R$, in cents
  deposit_status      TEXT DEFAULT 'nao_solicitado',
    -- nao_solicitado → aguardando_confirmacao → confirmado

  -- Pipeline
  pipeline_status     TEXT NOT NULL DEFAULT 'novo',
    -- novo → qualificando → orcamento_enviado → aguardando_deposito
    -- → agendado → fechado
    -- → aguardando_artista
    -- → perdido
  handoff_reason      TEXT,

  -- Timestamps
  conversation_started TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_message_at      TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_leads_artist_phone ON leads (artist_id, telefone);
CREATE INDEX idx_leads_pipeline ON leads (artist_id, pipeline_status);

-- RLS: every query scoped to current artist
ALTER TABLE leads ENABLE ROW LEVEL SECURITY;

CREATE POLICY artist_isolation ON leads
  USING (artist_id = current_setting('app.artist_id')::uuid);
```

### Table: `artists`

Per-artist configuration. Set up once, referenced by every lead query.

```sql
CREATE TABLE artists (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nome              TEXT NOT NULL,
  specialties       TEXT[],               -- e.g. ['realismo', 'blackwork', 'old_school']
  nao_faco          TEXT[],               -- placements the artist declines
  floor_pct         NUMERIC(5,2),         -- e.g. 80 = 80% floor
  deposit_type      TEXT DEFAULT 'percent', -- 'percent' | 'fixed'
  deposit_value     INTEGER,              -- % value or fixed R$ cents
  pix_key           TEXT,
  instagram_handle  TEXT,
  working_hours     JSONB,                -- { "seg": ["09:00-12:00","14:00-18:00"], ... }
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE artists ENABLE ROW LEVEL SECURITY;

CREATE POLICY artist_self_only ON artists
  USING (id = current_setting('app.artist_id')::uuid);
```

### Table: `pricing`

Placement × body-zone pricing per artist. Beatriz calls `lookup_price` against this.

```sql
CREATE TABLE pricing (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  placement     TEXT NOT NULL,
  body_zone     TEXT NOT NULL,   -- pequeno / médio / grande / fechamento
  table_price   INTEGER NOT NULL,           -- R$ cents
  session_duration_min INTEGER NOT NULL,
  buffer_min    INTEGER DEFAULT 30,
  creeps        INTEGER DEFAULT 0,          -- how many times the price has crept
  active        BOOLEAN DEFAULT true,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),

  UNIQUE (artist_id, placement, body_zone)
);

CREATE INDEX idx_pricing_artist ON pricing (artist_id, active);
```

### Table: `calendar`

Artist availability. Beatriz reads for booking slots; writes on successful booking.

```sql
CREATE TABLE calendar (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  start_at      TIMESTAMPTZ NOT NULL,
  end_at        TIMESTAMPTZ NOT NULL,
  type          TEXT NOT NULL DEFAULT 'available',
    -- 'available' | 'blocked' (holiday, off-day) | 'booked' (real lead occupies it)
  lead_id       UUID REFERENCES leads(id),  -- null unless type = 'booked'
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_calendar_artist_range ON calendar (artist_id, start_at, end_at);
```

### Table: `events` (immutable log)

Every state transition, quote, booking, and handoff is written here. Append-only. Used for: conversation context injection (Beatriz can read the last N events on a lead), debugging, and audit. Not queried by the artist directly.

```sql
CREATE TABLE events (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  lead_id     UUID NOT NULL REFERENCES leads(id),
  artist_id   UUID NOT NULL REFERENCES artists(id),
  event_type  TEXT NOT NULL,
    -- 'created', 'qualification_updated', 'quote_sent', 'deposit_requested',
    -- 'deposit_confirmed', 'slot_booked', 'handoff_triggered',
    -- 'pipeline_state_changed', 'lead_reopened', 'followup_sent'
  payload     JSONB,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_events_lead ON events (lead_id, created_at);
```

### Transcript storage

Conversation transcripts live in **Postgres Chat Memory** (n8n native node), keyed by `lead_id`. Per the runtime stack decision: the node auto-manages window trimming, passing the last N messages + earlier summary to the LLM.

Archived/dead conversations remain in Postgres Chat Memory. They're pruned naturally by the window trimming, and the `events` table provides the structured timeline. No separate transcript table is needed in v1 — Chat Memory is the transcript store.

## Operations Interface

The CRUD surface Beatriz calls. Each maps to a Postgres query:

### Lead lifecycle

| # | Operation | Args | SQL | Trigger |
|---|---|---|---|---|
| 1 | `create_lead` | artist_id, telefone, nome? | INSERT INTO leads | Inbound message from unknown number |
| 2 | `load_lead` | telefone, artist_id | SELECT * FROM leads WHERE artist_id= AND telefone= | Every inbound message (cache in n8n session) |
| 3 | `update_qualification` | lead_id, field → value pairs | UPDATE leads SET ... | Each discovery answer |
| 4 | `mark_pipeline_state` | lead_id, new_state | UPDATE leads SET pipeline_status=; INSERT INTO events | Phase transitions |

### Pricing

| # | Operation | Args | SQL | Trigger |
|---|---|---|---|---|
| 5 | `lookup_price` | placement, body_zone, artist_id | SELECT * FROM pricing WHERE artist_id= AND placement= AND body_zone= AND active=true | After doubt-clearing |
| 6 | `write_quote` | lead_id, table_price | UPDATE leads SET table_price=, negotiated_price=, pipeline_status='orcamento_enviado'; INSERT INTO events | Beatriz presents price |

### Deposit → Booking

| # | Operation | Args | SQL | Trigger |
|---|---|---|---|---|
| 7 | `request_deposit` | lead_id, amount, pix_key | UPDATE leads SET deposit_amount=, deposit_status='aguardando_confirmacao', pipeline_status='aguardando_deposito'; INSERT INTO events | Lead accepts price |
| 8 | `confirm_deposit` | lead_id | UPDATE leads SET deposit_status='confirmado'; INSERT INTO events | Artist confirms receipt (human action) |
| 9 | `check_availability` | artist_id, date_range, duration_min | SELECT * FROM calendar WHERE artist_id= AND start_at BETWEEN AND end_at BETWEEN AND type='available' | After deposit confirmed |
| 10 | `book_slot` | lead_id, date, duration, buffer | UPDATE leads SET booked_date=, session_duration_min=, buffer_min=, pipeline_status='agendado'; INSERT INTO calendar (type='booked', lead_id=); INSERT INTO events | Slot chosen |

### Handoff and closure

| # | Operation | Args | SQL | Trigger |
|---|---|---|---|---|
| 11 | `mark_handoff` | lead_id, reason | UPDATE leads SET pipeline_status='aguardando_artista', handoff_reason=; INSERT INTO events | Any handoff trigger fires |
| 12 | `close_won` | lead_id | UPDATE leads SET pipeline_status='fechado'; INSERT INTO events | Booking complete |
| 13 | `close_lost` | lead_id | UPDATE leads SET pipeline_status='perdido'; INSERT INTO events | Decline / no-deposit timeout / silence |

### Read-only lookups

| # | Operation | Args | Returns | Used when |
|---|---|---|---|---|
| R1 | `load_artist_config` | artist_id | artists row | Session init, any time config needed |
| R2 | `lookup_price` | placement, body_zone, artist_id | pricing row | Before quoting (same as #5 above) |
| R3 | `lookup_lead_events` | lead_id, limit N | last N events | Context injection for LLM prompt |

## Pipeline States (revised for deposit-before-booking)

The conversation contract had 5 states. With the deposit-first flow:

```
novo → qualificando → orcamento_enviado → aguardando_deposito → agendado → fechado
                                                                              → perdido
                                                              → aguardando_artista
```

| State | Meaning | Entered by |
|---|---|---|
| **novo** | Lead just messaged, no engagement yet | `create_lead` |
| **qualificando** | Beatriz is in discovery/value/doubt-clearing | `mark_pipeline_state` on first qualification write |
| **orcamento_enviado** | Price presented, awaiting lead response | `write_quote` |
| **aguardando_deposito** | Lead accepted, PIX sent, awaiting confirmation | `request_deposit` |
| **agendado** | Deposit confirmed, slot booked | `book_slot` |
| **fechado** | Closed won (artist marks complete) | `close_won` |
| **aguardando_artista** | Handoff triggered, artist must take over | `mark_handoff` |
| **perdido** | Declined, silence, no-deposit timeout | `close_lost` |

**Deposit timeout rule**: if `deposit_status = 'aguardando_confirmacao'` and no confirmation within 48h, Beatriz sends one follow-up. After 72h total with no confirmation: `pipeline_status = 'perdido'`, booked_date cleared (calendar block not yet created at this stage — no release needed).

## Multi-Tenancy

- Every table carries `artist_id` as a foreign key to `artists`.
- Every Beatriz query filters `WHERE artist_id = $1`, sourced from the WAHA session metadata (each WAHA session stores its `artist_id` in per-session metadata at creation time).
- **Row-Level Security** enforces this at the database level — a miswritten query without the filter returns zero rows.
- n8n sets `app.artist_id` in the session at workflow start, scoped from the WAHA webhook's session name → metadata lookup.

## What's Not in This Contract

- **Sync to Notion**: the pipeline that mirrors Postgres lead data into the artist's Notion workspace. A separate task ticket, downstream of this contract. Beatriz never touches Notion directly.
- **Self-built CRM**: if ticket 09 lands CRM-first, it reads from the same Postgres tables — no schema change.
- **Lead-source attribution logic**: the `lead_source` column is a placeholder. Mechanism TBD (fog on the map).
- **Pricing creep logic**: the `creeps` counter is stored. The nudge algorithm (apply +5% after each close, toggle on/off) is a workflow concern, not a schema concern.

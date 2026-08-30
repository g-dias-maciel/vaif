-- VAIF SDR Closer — full schema migration
-- Merges crm-write-contract + artist-onboarding-tenancy
-- Target: Supabase Postgres

-- ============================================================
-- 1. artists
-- ============================================================
CREATE TABLE artists (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nome              TEXT NOT NULL,
  specialties       TEXT[],
  nao_faco          TEXT[],
  floor_pct         NUMERIC(5,2),
  deposit_type      TEXT DEFAULT 'percent',
  deposit_value     INTEGER,
  pix_key           TEXT,
  instagram_handle  TEXT,
  working_hours     JSONB,
  ai_active_hours   JSONB,
  timezone          TEXT,
  wa_session_slug   TEXT UNIQUE,
  status            TEXT NOT NULL DEFAULT 'stub'
    CHECK (status IN ('stub','onboarding','live','suspended','offboarded')),
  onboarding_token  TEXT UNIQUE,
  whatsapp_number   TEXT,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE artists ENABLE ROW LEVEL SECURITY;

CREATE POLICY artist_self_only ON artists
  USING (id = current_setting('app.artist_id')::uuid)
  WITH CHECK (id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 2. leads
-- ============================================================
CREATE TABLE leads (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),

  -- Contact
  nome          TEXT,
  telefone      TEXT NOT NULL,
  lead_source   TEXT,

  -- Qualification
  placement           TEXT,
  body_zone           TEXT,
  style               TEXT,
  primeira_tatuagem   BOOLEAN,
  significado         TEXT,
  tipo_tatuagem       TEXT,
  reference_pics      TEXT[],

  -- Pricing
  table_price         INTEGER,
  negotiated_price    INTEGER,
  discount_percent    NUMERIC(5,2),
  contrapartida       TEXT DEFAULT 'Instagram post + fechando agora',

  -- Booking
  booked_date         TIMESTAMPTZ,
  session_duration_min INTEGER,
  buffer_min          INTEGER,

  -- Deposit
  deposit_amount      INTEGER,
  deposit_status      TEXT DEFAULT 'nao_solicitado',

  -- Pipeline
  pipeline_status     TEXT NOT NULL DEFAULT 'novo',
  handoff_reason      TEXT,

  -- Timestamps
  conversation_started TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_message_at      TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_leads_artist_phone ON leads (artist_id, telefone);
CREATE INDEX idx_leads_pipeline ON leads (artist_id, pipeline_status);

ALTER TABLE leads ENABLE ROW LEVEL SECURITY;

CREATE POLICY artist_isolation ON leads
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 3. pricing
-- ============================================================
CREATE TABLE pricing (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  placement     TEXT NOT NULL,
  body_zone     TEXT NOT NULL,
  table_price   INTEGER NOT NULL,
  session_duration_min INTEGER NOT NULL,
  buffer_min    INTEGER DEFAULT 30,
  creeps        INTEGER DEFAULT 0,
  active        BOOLEAN DEFAULT true,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),

  UNIQUE (artist_id, placement, body_zone)
);

CREATE INDEX idx_pricing_artist ON pricing (artist_id, active);

ALTER TABLE pricing ENABLE ROW LEVEL SECURITY;

CREATE POLICY pricing_artist_isolation ON pricing
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 4. calendar
-- ============================================================
CREATE TABLE calendar (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  start_at      TIMESTAMPTZ NOT NULL,
  end_at        TIMESTAMPTZ NOT NULL,
  type          TEXT NOT NULL DEFAULT 'available',
  lead_id       UUID REFERENCES leads(id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_calendar_artist_range ON calendar (artist_id, start_at, end_at);

ALTER TABLE calendar ENABLE ROW LEVEL SECURITY;

CREATE POLICY calendar_artist_isolation ON calendar
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 5. events (append-only immutable log)
-- ============================================================
CREATE TABLE events (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  lead_id     UUID NOT NULL REFERENCES leads(id),
  artist_id   UUID NOT NULL REFERENCES artists(id),
  event_type  TEXT NOT NULL,
  payload     JSONB,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_events_lead ON events (lead_id, created_at);

ALTER TABLE events ENABLE ROW LEVEL SECURITY;

CREATE POLICY events_artist_isolation ON events
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- Helper: set artist context for the current session
-- ============================================================
-- n8n calls this at workflow start:
--   SELECT set_artist_context('550e8400-e29b-41d4-a716-446655440000');
-- RLS policies above will then scope every query to that artist_id.

CREATE OR REPLACE FUNCTION set_artist_context(target_artist_id UUID)
RETURNS VOID AS $$
BEGIN
  PERFORM set_config('app.artist_id', target_artist_id::TEXT, true);
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION current_artist_id()
RETURNS UUID AS $$
BEGIN
  RETURN current_setting('app.artist_id', true)::UUID;
END;
$$ LANGUAGE plpgsql STABLE;

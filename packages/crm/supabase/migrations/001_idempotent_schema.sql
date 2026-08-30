-- Idempotent schema migration — safe to run on every workflow execution
-- Uses IF NOT EXISTS and ON CONFLICT DO NOTHING

CREATE TABLE IF NOT EXISTS artists (
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
  notion_token      TEXT,
  notion_clientes_database_id  TEXT,
  notion_projects_database_id  TEXT,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Reconcile artists schema if the table already existed with a different shape
-- (older deployments may lack these columns). Safe to run every time.
ALTER TABLE artists ADD COLUMN IF NOT EXISTS display_name        TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS nome               TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS specialties        TEXT[];
ALTER TABLE artists ADD COLUMN IF NOT EXISTS nao_faco           TEXT[];
ALTER TABLE artists ADD COLUMN IF NOT EXISTS floor_pct          NUMERIC(5,2);
ALTER TABLE artists ADD COLUMN IF NOT EXISTS deposit_type       TEXT DEFAULT 'percent';
ALTER TABLE artists ADD COLUMN IF NOT EXISTS deposit_value      INTEGER;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS pix_key            TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS instagram_handle   TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS working_hours      JSONB;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS ai_active_hours    JSONB;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS timezone           TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS wa_session_slug    TEXT UNIQUE;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS status             TEXT NOT NULL DEFAULT 'stub';
ALTER TABLE artists ADD COLUMN IF NOT EXISTS onboarding_token   TEXT UNIQUE;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS whatsapp_number    TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS notion_token       TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS notion_clientes_database_id TEXT;
ALTER TABLE artists ADD COLUMN IF NOT EXISTS notion_projects_database_id TEXT;

CREATE TABLE IF NOT EXISTS leads (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  nome          TEXT,
  telefone      TEXT NOT NULL,
  lead_source   TEXT,
  placement           TEXT,
  body_zone           TEXT,
  style               TEXT,
  primeira_tatuagem   BOOLEAN,
  significado         TEXT,
  tipo_tatuagem       TEXT,
  reference_pics      TEXT[],
  table_price         INTEGER,
  negotiated_price    INTEGER,
  discount_percent    NUMERIC(5,2),
  contrapartida       TEXT DEFAULT 'Instagram post + fechando agora',
  booked_date         TIMESTAMPTZ,
  session_duration_min INTEGER,
  buffer_min          INTEGER,
  deposit_amount      INTEGER,
  deposit_status      TEXT DEFAULT 'nao_solicitado',
  pipeline_status     TEXT NOT NULL DEFAULT 'novo',
  handoff_reason      TEXT,
  conversation_started TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_message_at      TIMESTAMPTZ,
  notion_sync_status   TEXT DEFAULT 'pending'
    CHECK (notion_sync_status IN ('pending','synced','failed')),
  notion_synced_at     TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Reconcile leads schema if the table already existed with a different shape.
ALTER TABLE leads ADD COLUMN IF NOT EXISTS nome                  TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS placement             TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS body_zone             TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS style                 TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS primeira_tatuagem     BOOLEAN;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS significado           TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS tipo_tatuagem         TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS reference_pics        TEXT[];
ALTER TABLE leads ADD COLUMN IF NOT EXISTS table_price           INTEGER;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS negotiated_price      INTEGER;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS discount_percent      NUMERIC(5,2);
ALTER TABLE leads ADD COLUMN IF NOT EXISTS contrapartida         TEXT DEFAULT 'Instagram post + fechando agora';
ALTER TABLE leads ADD COLUMN IF NOT EXISTS booked_date           TIMESTAMPTZ;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS session_duration_min  INTEGER;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS buffer_min            INTEGER;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS deposit_amount        INTEGER;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS deposit_status        TEXT DEFAULT 'nao_solicitado';
ALTER TABLE leads ADD COLUMN IF NOT EXISTS pipeline_status       TEXT NOT NULL DEFAULT 'novo';
ALTER TABLE leads ADD COLUMN IF NOT EXISTS handoff_reason        TEXT;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS conversation_started  TIMESTAMPTZ NOT NULL DEFAULT now();
ALTER TABLE leads ADD COLUMN IF NOT EXISTS last_message_at       TIMESTAMPTZ;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS notion_sync_status    TEXT DEFAULT 'pending';
ALTER TABLE leads ADD COLUMN IF NOT EXISTS notion_synced_at      TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_leads_artist_phone ON leads (artist_id, telefone);
CREATE INDEX IF NOT EXISTS idx_leads_pipeline ON leads (artist_id, pipeline_status);
CREATE INDEX IF NOT EXISTS idx_leads_notion_sync ON leads (notion_sync_status) WHERE notion_sync_status = 'pending';

CREATE TABLE IF NOT EXISTS pricing (
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

CREATE INDEX IF NOT EXISTS idx_pricing_artist ON pricing (artist_id, active);

CREATE TABLE IF NOT EXISTS calendar (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  start_at      TIMESTAMPTZ NOT NULL,
  end_at        TIMESTAMPTZ NOT NULL,
  type          TEXT NOT NULL DEFAULT 'available',
  lead_id       UUID REFERENCES leads(id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_calendar_artist_range ON calendar (artist_id, start_at, end_at);

CREATE TABLE IF NOT EXISTS events (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  lead_id     UUID NOT NULL REFERENCES leads(id),
  artist_id   UUID NOT NULL REFERENCES artists(id),
  event_type  TEXT NOT NULL,
  payload     JSONB,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_events_lead ON events (lead_id, created_at);

-- Message debounce buffer — holds in-flight messages per chat so the agent
-- waits for the lead to finish typing before answering the whole burst.
CREATE TABLE IF NOT EXISTS message_buffer (
  chat_id        TEXT PRIMARY KEY,
  pending        TEXT,
  last_msg_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Notion sync outbox — guarantees at-least-once delivery of pipeline
-- changes to Notion. The Beatriz flow enqueues here (fast, local); a
-- scheduled consumer drains pending rows and syncs to Notion with retries.
CREATE TABLE IF NOT EXISTS notion_sync_outbox (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  lead_id       UUID NOT NULL REFERENCES leads(id),
  artist_id     UUID NOT NULL REFERENCES artists(id),
  payload       JSONB NOT NULL,
  status        TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','processing','done','failed')),
  attempts      INTEGER NOT NULL DEFAULT 0,
  next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_error    TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  processed_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_outbox_pending ON notion_sync_outbox (next_attempt_at)
  WHERE status IN ('pending','failed');

CREATE OR REPLACE FUNCTION set_artist_context(target_artist_id UUID)
RETURNS VOID AS $$
BEGIN
  PERFORM set_config('app.artist_id', target_artist_id::TEXT, true);
END;
$$ LANGUAGE plpgsql;

-- Resolve the artist for an incoming WAHA session slug (session -> artist_id).
-- Filters to active lifecycle states so suspended/offboarded artists get no match.
CREATE OR REPLACE FUNCTION resolve_artist_from_session(
  p_wa_session_slug TEXT
) RETURNS SETOF artists AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM artists
    WHERE wa_session_slug = p_wa_session_slug
      AND status IN ('stub', 'onboarding', 'live')
    LIMIT 1;
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

-- Validate onboarding token (exists, still in stub, not older than 24h).
CREATE OR REPLACE FUNCTION validate_onboarding_token(
  p_token TEXT
) RETURNS SETOF artists AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM artists
    WHERE onboarding_token = p_token
      AND status = 'stub'
      AND created_at > now() - INTERVAL '24 hours';
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

-- Consume onboarding token: activate artist.
-- NOTE: onboarding_token is KEPT (not nulled) so it stays the artist's stable
-- per-artist link — reused by /agenda once live.
CREATE OR REPLACE FUNCTION consume_onboarding_token(
  p_token TEXT
) RETURNS SETOF artists AS $$
DECLARE
  v_artist artists;
BEGIN
  UPDATE artists SET
    status = 'onboarding',
    wa_session_slug = wa_session_slug  -- no-op, keeps existing slug
  WHERE onboarding_token = p_token
    AND status = 'stub'
    AND created_at > now() - INTERVAL '24 hours'
  RETURNING * INTO v_artist;

  IF v_artist.id IS NOT NULL THEN
    RETURN NEXT v_artist;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Transition artist from onboarding to live.
CREATE OR REPLACE FUNCTION complete_artist_onboarding(
  p_artist_id UUID
) RETURNS SETOF artists AS $$
DECLARE
  v_artist artists;
BEGIN
  UPDATE artists SET
    status = 'live'
  WHERE id = p_artist_id
    AND status = 'onboarding'
  RETURNING * INTO v_artist;

  IF v_artist.id IS NOT NULL THEN
    RETURN NEXT v_artist;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION lookup_price(
  p_placement TEXT,
  p_body_zone TEXT,
  p_artist_id UUID
) RETURNS TABLE(
  placement TEXT,
  body_zone TEXT,
  table_price INTEGER,
  session_duration_min INTEGER,
  buffer_min INTEGER
) AS $$
BEGIN
  RETURN QUERY
    SELECT p.placement, p.body_zone, p.table_price, p.session_duration_min, p.buffer_min
    FROM pricing p
    WHERE p.artist_id = p_artist_id
      AND p.placement = p_placement
      AND p.body_zone = p_body_zone
      AND p.active = true
    LIMIT 1;
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION write_quote(
  p_lead_id UUID,
  p_table_price INTEGER,
  p_negotiated_price INTEGER DEFAULT NULL
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    table_price = p_table_price,
    negotiated_price = COALESCE(p_negotiated_price, p_table_price),
    pipeline_status = 'orcamento_enviado',
    updated_at = now()
  WHERE id = p_lead_id
  RETURNING * INTO v_lead;
  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, v_lead.artist_id, 'quote_sent',
            jsonb_build_object('table_price', p_table_price, 'negotiated_price', COALESCE(p_negotiated_price, p_table_price)));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION request_deposit(
  p_lead_id UUID,
  p_amount INTEGER
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    deposit_amount = p_amount,
    deposit_status = 'aguardando_confirmacao',
    pipeline_status = 'aguardando_deposito',
    updated_at = now()
  WHERE id = p_lead_id
  RETURNING * INTO v_lead;
  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, v_lead.artist_id, 'deposit_requested', jsonb_build_object('amount', p_amount));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql;

-- Availability is DERIVED from the artist's weekly working hours instead of
-- pre-seeded calendar rows. working_hours is a JSONB object keyed by weekday
-- (seg/ter/qua/qui/sex/sab/dom) whose values are arrays of "HH:MM-HH:MM"
-- blocks, interpreted in the artist's own timezone (artists.timezone, not UTC).
-- A slot is offered for every hour-aligned start that fits inside a working
-- block and is long enough for the requested duration; it is free unless a
-- 'booked' or 'blocked' calendar row overlaps it. Legacy 'available' rows are
-- ignored entirely.
CREATE OR REPLACE FUNCTION check_availability(
  p_artist_id UUID,
  p_from_date TIMESTAMPTZ,
  p_to_date TIMESTAMPTZ,
  p_duration_min INTEGER DEFAULT 60
) RETURNS TABLE(
  id UUID,
  start_at TIMESTAMPTZ,
  end_at TIMESTAMPTZ,
  type TEXT
) AS $$
DECLARE
  v_tz      TEXT;
  v_working JSONB;
  v_day0    DATE;
  v_day1    DATE;
  v_dur     INTERVAL;
BEGIN
  SELECT a.timezone, a.working_hours INTO v_tz, v_working
  FROM artists a
  WHERE a.id = p_artist_id;

  IF v_working IS NULL THEN
    RETURN;
  END IF;

  v_tz := COALESCE(NULLIF(v_tz, ''), 'UTC');
  v_day0 := (p_from_date AT TIME ZONE v_tz)::date;
  v_day1 := (p_to_date AT TIME ZONE v_tz)::date;
  v_dur := make_interval(mins => GREATEST(p_duration_min, 1));

  RETURN QUERY
  WITH days AS (
    SELECT generate_series(v_day0::timestamp, v_day1::timestamp, '1 day'::interval)::date AS day
  ),
  weeks AS (
    SELECT day,
           (ARRAY['seg','ter','qua','qui','sex','sab','dom'])[EXTRACT(ISODOW FROM day)::int] AS dow
    FROM days
  ),
  blocks AS (
    SELECT w.day, w.dow, b.value AS block
    FROM weeks w
    CROSS JOIN LATERAL jsonb_array_elements_text(v_working -> w.dow) AS b(value)
  ),
  spans AS (
    SELECT
      b.day,
      (b.day + split_part(b.block, '-', 1)::time)::timestamp AS start_local,
      (b.day + split_part(b.block, '-', 2)::time)::timestamp AS end_local
    FROM blocks b
  ),
  starts AS (
    SELECT
      ((s.start_local + (n || ' hours')::interval) AT TIME ZONE v_tz) AS slot_start,
      (((s.start_local + (n || ' hours')::interval) AT TIME ZONE v_tz) + v_dur) AS slot_end,
      (s.end_local AT TIME ZONE v_tz) AS block_end
    FROM spans s
    CROSS JOIN LATERAL generate_series(
      0,
      GREATEST(0, ceil(EXTRACT(EPOCH FROM (s.end_local - s.start_local)) / 3600)::int - 1)
    ) AS n
  )
  SELECT
    md5(p_artist_id::text || st.slot_start::text || p_duration_min::text)::uuid AS id,
    st.slot_start,
    st.slot_end,
    'available'::text AS type
  FROM starts st
  WHERE st.slot_start >= p_from_date
    AND st.slot_end <= p_to_date
    AND st.slot_end <= st.block_end
    AND NOT EXISTS (
      SELECT 1 FROM calendar c
      WHERE c.artist_id = p_artist_id
        AND c.type IN ('booked', 'blocked')
        AND c.start_at < st.slot_end
        AND c.end_at > st.slot_start
    )
  ORDER BY st.slot_start;
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION book_slot(
  p_lead_id UUID,
  p_start_at TIMESTAMPTZ,
  p_duration_min INTEGER,
  p_buffer_min INTEGER DEFAULT 30
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_end_at TIMESTAMPTZ;
BEGIN
  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  IF NOT FOUND THEN RETURN; END IF;
  v_end_at := p_start_at + (p_duration_min || ' minutes')::INTERVAL;

  -- Reject (don't insert) when the chosen window overlaps an existing
  -- 'booked' or 'blocked' period for the same artist — no double-booking.
  IF EXISTS (
    SELECT 1 FROM calendar c
    WHERE c.artist_id = v_lead.artist_id
      AND c.type IN ('booked', 'blocked')
      AND c.start_at < v_end_at
      AND c.end_at > p_start_at
  ) THEN
    RETURN;
  END IF;

  INSERT INTO calendar (artist_id, start_at, end_at, type, lead_id)
  VALUES (v_lead.artist_id, p_start_at, v_end_at, 'booked', p_lead_id);
  UPDATE leads SET
    booked_date = p_start_at,
    session_duration_min = p_duration_min,
    buffer_min = p_buffer_min,
    pipeline_status = 'agendado',
    updated_at = now()
  WHERE id = p_lead_id
  RETURNING * INTO v_lead;
  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, v_lead.artist_id, 'slot_booked',
            jsonb_build_object('start_at', p_start_at, 'end_at', v_end_at, 'duration_min', p_duration_min, 'buffer_min', p_buffer_min));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql;

-- Block a period for an artist: creates a type='blocked' calendar row so
-- check_availability stops offering those hours and book_slot rejects them.
CREATE OR REPLACE FUNCTION block_slot(
  p_artist_id UUID,
  p_start_at TIMESTAMPTZ,
  p_end_at TIMESTAMPTZ
) RETURNS SETOF calendar AS $$
DECLARE
  v_block calendar;
BEGIN
  INSERT INTO calendar (artist_id, start_at, end_at, type)
  VALUES (p_artist_id, p_start_at, p_end_at, 'blocked')
  RETURNING * INTO v_block;
  RETURN NEXT v_block;
END;
$$ LANGUAGE plpgsql;

-- Remove a blocked period by its calendar row id.
CREATE OR REPLACE FUNCTION unblock_slot(
  p_block_id UUID
) RETURNS SETOF calendar AS $$
DECLARE
  v_block calendar;
BEGIN
  DELETE FROM calendar
  WHERE id = p_block_id AND type = 'blocked'
  RETURNING * INTO v_block;
  IF FOUND THEN
    RETURN NEXT v_block;
  END IF;
END;
$$ LANGUAGE plpgsql;

-- Seed: artist Bruno (safe to re-run)
-- display_name is NOT NULL in some deployments, so it is always populated.
-- ai_active_hours: janela em que a Beatriz atende, no fuso do artista (timezone).
--   Ex: '{"start":"19:00","end":"08:00"}' = atende só à noite, silêncio de dia.
--   NULL = Beatriz sempre ativa (padrão).
-- timezone: IANA (ex: America/Sao_Paulo) — o horário de ai_active_hours é interpretado nele.
INSERT INTO artists (id, display_name, nome, specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key, instagram_handle, working_hours, ai_active_hours, timezone, wa_session_slug, status, whatsapp_number)
VALUES (
  'b0000000-0000-0000-0000-000000000001',
  'Bruno',
  'Bruno',
  ARRAY['realismo', 'blackwork', 'old_school'],
  ARRAY['rosto', 'partes_intimas', 'dedos'],
  80.00, 'percent', 30,
  'bruno.tattoo@pix.com.br',
  '@bruno.tattoo',
  '{"seg":["09:00-12:00","14:00-18:00"],"ter":["09:00-12:00","14:00-18:00"],"qua":["09:00-12:00","14:00-18:00"],"qui":["09:00-12:00","14:00-18:00"],"sex":["09:00-12:00","14:00-18:00"],"sab":["09:00-13:00"]}'::jsonb,
  NULL,
  'America/Sao_Paulo',
  'bruno-tattoo', 'live', '5511999990001'
) ON CONFLICT (id) DO UPDATE SET
  display_name      = EXCLUDED.display_name,
  nome              = EXCLUDED.nome,
  specialties       = EXCLUDED.specialties,
  nao_faco          = EXCLUDED.nao_faco,
  floor_pct         = EXCLUDED.floor_pct,
  deposit_type      = EXCLUDED.deposit_type,
  deposit_value     = EXCLUDED.deposit_value,
  pix_key           = EXCLUDED.pix_key,
  instagram_handle  = EXCLUDED.instagram_handle,
  working_hours     = EXCLUDED.working_hours,
  ai_active_hours   = EXCLUDED.ai_active_hours,
  timezone          = EXCLUDED.timezone,
  wa_session_slug   = EXCLUDED.wa_session_slug,
  status            = EXCLUDED.status,
  whatsapp_number   = EXCLUDED.whatsapp_number;

-- Seed: pricing (safe to re-run)
INSERT INTO pricing (artist_id, placement, body_zone, table_price, session_duration_min, buffer_min) VALUES
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'pequeno',    30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'medio',      60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'grande',     90000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'fechamento', 120000, 240, 30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'pequeno',  30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'medio',    60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'grande',   90000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'fechamento',150000, 300, 30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'pequeno',   35000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'medio',     70000, 150,  30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'grande',    120000, 240, 30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'fechamento',200000, 360, 30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'pequeno',   25000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'medio',     50000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'grande',    80000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'fechamento',110000, 240, 30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'pequeno',   30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'medio',     60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'grande',    100000, 180, 30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'fechamento',160000, 300, 30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'pequeno',   30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'medio',     60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'grande',    100000, 180, 30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'fechamento',140000, 300, 30)
ON CONFLICT (artist_id, placement, body_zone) DO NOTHING;

-- Seed: calendar
-- NOTE: no longer seeded. Availability is derived on the fly from
-- artists.working_hours (see check_availability), so pre-seeding 'available'
-- rows is both unnecessary and harmful — stale slots would never expire.
-- The calendar table only ever holds 'booked'/'blocked' rows from here on.

-- ── Notion sync outbox helpers ──

-- Enqueue a lead's current state into the Notion outbox.
-- Coalesces multiple queued entries for the same lead into one (idempotent upsert
-- on the latest pending row) so bursts of pipeline updates collapse to a single sync.
CREATE OR REPLACE FUNCTION enqueue_notion_sync(
  p_lead_id UUID,
  p_artist_id UUID,
  p_payload JSONB
) RETURNS UUID AS $$
DECLARE
  v_id UUID;
  v_pending UUID;
BEGIN
  -- Reuse an existing pending row for this lead, otherwise create one.
  SELECT id INTO v_pending
  FROM notion_sync_outbox
  WHERE lead_id = p_lead_id AND status = 'pending'
  ORDER BY created_at DESC
  LIMIT 1;

  IF v_pending IS NOT NULL THEN
    UPDATE notion_sync_outbox
    SET payload = p_payload, created_at = now(), next_attempt_at = now()
    WHERE id = v_pending
    RETURNING id INTO v_id;
  ELSE
    INSERT INTO notion_sync_outbox (lead_id, artist_id, payload)
    VALUES (p_lead_id, p_artist_id, p_payload)
    RETURNING id INTO v_id;
  END IF;

  UPDATE leads SET
    notion_sync_status = 'pending',
    updated_at = now()
  WHERE id = p_lead_id;

  RETURN v_id;
END;
$$ LANGUAGE plpgsql;

-- Claim a batch of due outbox rows for processing (consumer workflow calls this).
-- Marks them 'processing' so concurrent runs don't double-sync.
CREATE OR REPLACE FUNCTION claim_notion_sync_rows(
  p_limit INTEGER DEFAULT 50
) RETURNS SETOF notion_sync_outbox AS $$
BEGIN
  RETURN QUERY
  WITH claimed AS (
    SELECT id
    FROM notion_sync_outbox
    WHERE status IN ('pending','failed')
      AND next_attempt_at <= now()
    ORDER BY created_at
    LIMIT p_limit
    FOR UPDATE SKIP LOCKED
  )
  UPDATE notion_sync_outbox o
  SET status = 'processing', attempts = o.attempts + 1
  FROM claimed c
  WHERE o.id = c.id
  RETURNING o.*;
END;
$$ LANGUAGE plpgsql;

-- Mark an outbox row as done after a successful Notion sync.
CREATE OR REPLACE FUNCTION complete_notion_sync_row(p_id UUID) RETURNS VOID AS $$
BEGIN
  UPDATE notion_sync_outbox
  SET status = 'done', processed_at = now(), last_error = NULL
  WHERE id = p_id;

  UPDATE leads l SET
    notion_sync_status = 'synced',
    notion_synced_at = now(),
    updated_at = now()
  FROM notion_sync_outbox o
  WHERE o.id = p_id AND o.lead_id = l.id;
END;
$$ LANGUAGE plpgsql;

-- Fail an outbox row; schedule retry with exponential backoff.
CREATE OR REPLACE FUNCTION fail_notion_sync_row(p_id UUID, p_error TEXT) RETURNS VOID AS $$
DECLARE
  v_attempts INTEGER;
BEGIN
  SELECT attempts INTO v_attempts FROM notion_sync_outbox WHERE id = p_id;

  UPDATE notion_sync_outbox
  SET status = CASE WHEN v_attempts >= 5 THEN 'failed' ELSE 'pending' END,
      last_error = p_error,
      next_attempt_at = now() + (LEAST(v_attempts * 60, 3600) || ' seconds')::INTERVAL
  WHERE id = p_id;

  UPDATE leads SET notion_sync_status = 'failed', updated_at = now()
  WHERE id = (SELECT lead_id FROM notion_sync_outbox WHERE id = p_id);
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- VAIF SDR Closer — Full Migration for Coolify Postgres
-- Copy and paste into Coolify's SQL editor.
-- Safe to re-run: uses IF NOT EXISTS / DROP IF EXISTS
-- ============================================================

DROP TABLE IF EXISTS events CASCADE;
DROP TABLE IF EXISTS calendar CASCADE;
DROP TABLE IF EXISTS pricing CASCADE;
DROP TABLE IF EXISTS leads CASCADE;
DROP TABLE IF EXISTS artists CASCADE;
DROP FUNCTION IF EXISTS set_artist_context;
DROP FUNCTION IF EXISTS current_artist_id;

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

-- ============================================================
-- 2. leads
-- ============================================================
CREATE TABLE leads (
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
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_leads_artist_phone ON leads (artist_id, telefone);
CREATE INDEX idx_leads_pipeline ON leads (artist_id, pipeline_status);

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

-- ============================================================
-- Helper: set artist context for the current session
-- ============================================================
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

-- ============================================================
-- CRUD Functions
-- ============================================================

CREATE OR REPLACE FUNCTION create_lead(
  p_artist_id UUID,
  p_telefone TEXT,
  p_nome TEXT DEFAULT NULL
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  INSERT INTO leads (artist_id, telefone, nome, conversation_started)
  VALUES (p_artist_id, p_telefone, p_nome, now())
  RETURNING * INTO v_lead;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (v_lead.id, p_artist_id, 'created',
          jsonb_build_object('telefone', p_telefone, 'nome', p_nome));

  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION load_lead(
  p_telefone TEXT,
  p_artist_id UUID
) RETURNS SETOF leads AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM leads
    WHERE artist_id = p_artist_id
      AND telefone = p_telefone
    ORDER BY created_at DESC
    LIMIT 1;
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

CREATE OR REPLACE FUNCTION update_qualification(
  p_lead_id UUID,
  p_artist_id UUID,
  p_placement TEXT DEFAULT NULL,
  p_body_zone TEXT DEFAULT NULL,
  p_style TEXT DEFAULT NULL,
  p_primeira_tatuagem BOOLEAN DEFAULT NULL,
  p_significado TEXT DEFAULT NULL
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    placement = COALESCE(p_placement, placement),
    body_zone = COALESCE(p_body_zone, body_zone),
    style = COALESCE(p_style, style),
    primeira_tatuagem = COALESCE(p_primeira_tatuagem, primeira_tatuagem),
    significado = COALESCE(p_significado, significado),
    pipeline_status = CASE WHEN pipeline_status = 'novo' THEN 'qualificando'::text ELSE pipeline_status END,
    updated_at = now()
  WHERE id = p_lead_id AND artist_id = p_artist_id
  RETURNING * INTO v_lead;

  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, p_artist_id, 'qualification_updated',
            jsonb_build_object(
              'placement', p_placement,
              'body_zone', p_body_zone,
              'style', p_style,
              'primeira_tatuagem', p_primeira_tatuagem,
              'significado', p_significado
            ));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION mark_pipeline_state(
  p_lead_id UUID,
  p_artist_id UUID,
  p_new_state TEXT
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    pipeline_status = p_new_state,
    updated_at = now()
  WHERE id = p_lead_id AND artist_id = p_artist_id
  RETURNING * INTO v_lead;

  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, p_artist_id, 'pipeline_state_changed',
            jsonb_build_object('from', (SELECT pipeline_status FROM leads WHERE id = p_lead_id), 'to', p_new_state));
    RETURN NEXT v_lead;
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
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

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
            jsonb_build_object(
              'table_price', p_table_price,
              'negotiated_price', COALESCE(p_negotiated_price, p_table_price)
            ));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

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
    VALUES (p_lead_id, v_lead.artist_id, 'deposit_requested',
            jsonb_build_object('amount', p_amount));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION confirm_deposit(
  p_lead_id UUID,
  p_artist_id UUID
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    deposit_status = 'confirmado',
    pipeline_status = 'agendamento',
    updated_at = now()
  WHERE id = p_lead_id AND artist_id = p_artist_id
  RETURNING * INTO v_lead;

  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, p_artist_id, 'deposit_confirmed', '{}'::jsonb);
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

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
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

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
  IF NOT FOUND THEN
    RETURN;
  END IF;

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
            jsonb_build_object(
              'start_at', p_start_at,
              'end_at', v_end_at,
              'duration_min', p_duration_min,
              'buffer_min', p_buffer_min
            ));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

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
$$ LANGUAGE plpgsql SECURITY DEFINER;

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
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION mark_handoff(
  p_lead_id UUID,
  p_artist_id UUID,
  p_reason TEXT DEFAULT NULL
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    pipeline_status = 'aguardando_artista',
    handoff_reason = p_reason,
    updated_at = now()
  WHERE id = p_lead_id AND artist_id = p_artist_id
  RETURNING * INTO v_lead;

  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, p_artist_id, 'handoff_triggered',
            jsonb_build_object('reason', p_reason));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION close_won(
  p_lead_id UUID,
  p_artist_id UUID
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    pipeline_status = 'fechado',
    updated_at = now()
  WHERE id = p_lead_id AND artist_id = p_artist_id
  RETURNING * INTO v_lead;

  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, p_artist_id, 'closed_won', '{}'::jsonb);
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION close_lost(
  p_lead_id UUID,
  p_artist_id UUID,
  p_reason TEXT DEFAULT NULL
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
BEGIN
  UPDATE leads SET
    pipeline_status = 'perdido',
    handoff_reason = p_reason,
    updated_at = now()
  WHERE id = p_lead_id AND artist_id = p_artist_id
  RETURNING * INTO v_lead;

  IF FOUND THEN
    INSERT INTO events (lead_id, artist_id, event_type, payload)
    VALUES (p_lead_id, p_artist_id, 'closed_lost',
            jsonb_build_object('reason', p_reason));
    RETURN NEXT v_lead;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ============================================================
-- Seed data — Sample artist "Bruno" + pricing
-- ============================================================
INSERT INTO artists (id, nome, specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key, instagram_handle, working_hours, ai_active_hours, timezone, wa_session_slug, status, whatsapp_number)
VALUES (
  'b0000000-0000-0000-0000-000000000001',
  'Bruno',
  ARRAY['realismo', 'blackwork', 'old_school'],
  ARRAY['rosto', 'partes_intimas', 'dedos'],
  80.00,
  'percent',
  30,
  'bruno.tattoo@pix.com.br',
  '@bruno.tattoo',
  '{"seg":["09:00-12:00","14:00-18:00"],"ter":["09:00-12:00","14:00-18:00"],"qua":["09:00-12:00","14:00-18:00"],"qui":["09:00-12:00","14:00-18:00"],"sex":["09:00-12:00","14:00-18:00"],"sab":["09:00-13:00"]}'::jsonb,
  NULL,
  'America/Sao_Paulo',
  'bruno-tattoo',
  'live',
  '5511999990001'
);

INSERT INTO pricing (artist_id, placement, body_zone, table_price, session_duration_min, buffer_min)
VALUES
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
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'fechamento',140000, 300, 30);

-- Calendar seed removed: availability is derived from artists.working_hours on
-- the fly (see check_availability). Pre-seeding 'available' rows is no longer
-- done — stale slots would never expire. The calendar table only ever holds
-- 'booked'/'blocked' rows from here on.

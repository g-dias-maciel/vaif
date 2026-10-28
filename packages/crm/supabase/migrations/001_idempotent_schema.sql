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
  telegram_group_id TEXT,
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
ALTER TABLE artists ADD COLUMN IF NOT EXISTS telegram_group_id  TEXT;
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
  processo_explicado  BOOLEAN DEFAULT false,
  preco_liberado      BOOLEAN DEFAULT false,
  duvidas_eliminadas  BOOLEAN DEFAULT false,
  aguardando_confirmacao_duvidas BOOLEAN DEFAULT false,
  audio_count         INTEGER DEFAULT 0,
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
ALTER TABLE leads ADD COLUMN IF NOT EXISTS processo_explicado    BOOLEAN DEFAULT false;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS preco_liberado        BOOLEAN DEFAULT false;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS duvidas_eliminadas    BOOLEAN DEFAULT false;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS aguardando_confirmacao_duvidas BOOLEAN DEFAULT false;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS audio_count           INTEGER DEFAULT 0;

-- Previous versions could let the classifier mark doubts as cleared before
-- the assistant actually asked the checkpoint. Fail closed for pre-quote leads.
UPDATE leads
SET duvidas_eliminadas = false,
    aguardando_confirmacao_duvidas = false
WHERE pipeline_status IN ('novo', 'qualificando')
  AND COALESCE(preco_liberado, false) = false;
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
-- last_typing_at/typing_state carry WAHA presence.update signals so the agent
-- can hold the reply while the lead is still composing.
CREATE TABLE IF NOT EXISTS message_buffer (
  chat_id        TEXT PRIMARY KEY,
  pending        TEXT,
  last_msg_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_typing_at TIMESTAMPTZ,
  typing_state   TEXT
);

-- Reconcile message_buffer if it already existed without presence columns.
ALTER TABLE message_buffer ADD COLUMN IF NOT EXISTS last_typing_at TIMESTAMPTZ;
ALTER TABLE message_buffer ADD COLUMN IF NOT EXISTS typing_state   TEXT;

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

-- Pause the SDR for a live artist (artist request / payment issue). The
-- WhatsApp agent's resolve_artist_from_session() only matches stub/onboarding/
-- live, so a suspended artist stops receiving answers and new bookings.
CREATE OR REPLACE FUNCTION suspend_artist(
  p_artist_id UUID
) RETURNS SETOF artists AS $$
DECLARE
  v_artist artists;
BEGIN
  UPDATE artists SET
    status = 'suspended'
  WHERE id = p_artist_id
    AND status = 'live'
  RETURNING * INTO v_artist;

  IF v_artist.id IS NOT NULL THEN
    RETURN NEXT v_artist;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Re-activate a suspended artist back to live.
CREATE OR REPLACE FUNCTION resume_artist(
  p_artist_id UUID
) RETURNS SETOF artists AS $$
DECLARE
  v_artist artists;
BEGIN
  UPDATE artists SET
    status = 'live'
  WHERE id = p_artist_id
    AND status = 'suspended'
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

  -- Serialize bookings per artist so two concurrent book_slot calls for the
  -- same/overlapping window can't both pass the overlap check and double-book.
  PERFORM pg_advisory_xact_lock(hashtext(v_lead.artist_id::text)::bigint);

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

-- Book a slot ONLY if it is a real, free, future slot derived from the
-- artist's working hours. Rejects hallucinated/past dates and already-booked
-- windows, returning no rows instead of writing a bad calendar entry.
CREATE OR REPLACE FUNCTION book_slot_checked(
  p_lead_id UUID,
  p_start_at TIMESTAMPTZ,
  p_duration_min INTEGER DEFAULT 120,
  p_buffer_min INTEGER DEFAULT 30
) RETURNS SETOF leads AS $$
DECLARE
  v_artist UUID;
  v_tz TEXT;
  v_candidate TIMESTAMPTZ;
BEGIN
  SELECT artist_id INTO v_artist FROM leads WHERE id = p_lead_id;
  IF v_artist IS NULL THEN
    RETURN;
  END IF;
  SELECT COALESCE(NULLIF(timezone, ''), 'UTC') INTO v_tz FROM artists WHERE id = v_artist;

  -- 1) Exact instant is a real, free, future slot.
  IF p_start_at > now() AND EXISTS (
    SELECT 1
    FROM check_availability(
      v_artist,
      p_start_at - interval '1 hour',
      p_start_at + (GREATEST(p_duration_min, 1) || ' minutes')::interval + interval '1 hour',
      GREATEST(p_duration_min, 1)
    )
    WHERE start_at = p_start_at
  ) THEN
    RETURN QUERY SELECT * FROM book_slot(p_lead_id, p_start_at, p_duration_min, p_buffer_min);
    RETURN;
  END IF;

  -- 2) Reconcile the model's timezone: it often sends the wall-clock time with
  --    a "Z" (UTC) suffix even though it means the artist's local time. Treat
  --    the requested clock time as the artist's local time and re-check.
  v_candidate := (p_start_at AT TIME ZONE 'UTC') AT TIME ZONE v_tz;
  IF v_candidate > now() AND EXISTS (
    SELECT 1
    FROM check_availability(
      v_artist,
      v_candidate - interval '1 hour',
      v_candidate + (GREATEST(p_duration_min, 1) || ' minutes')::interval + interval '1 hour',
      GREATEST(p_duration_min, 1)
    )
    WHERE start_at = v_candidate
  ) THEN
    RETURN QUERY SELECT * FROM book_slot(p_lead_id, v_candidate, p_duration_min, p_buffer_min);
    RETURN;
  END IF;

  -- Neither the exact instant nor its local-time reading is a real slot.
  RETURN;
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
  -- Serialize with book_slot on the same artist key so a block can't race a
  -- concurrent booking into an overlapping window.
  PERFORM pg_advisory_xact_lock(hashtext(p_artist_id::text)::bigint);

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
INSERT INTO artists (id, display_name, nome, specialties, nao_faco, floor_pct, deposit_type, deposit_value, pix_key, instagram_handle, working_hours, ai_active_hours, timezone, wa_session_slug, status, whatsapp_number, telegram_group_id)
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
  -- slug matches the WAHA session used for the test artist (dashboard session
  -- is named "default"); keep it in sync so re-running this migration doesn't
  -- remap the session out from under the agent.
  'default', 'live', '5511999990001', '-5195870017'
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
  whatsapp_number   = EXCLUDED.whatsapp_number,
  telegram_group_id = EXCLUDED.telegram_group_id;

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

-- ── Lead-history reset ──
-- Deletes lead history FK-safely. Pass NULL to wipe EVERY artist, or a session
-- slug (e.g. 'default') to wipe just that artist. Order matters: events,
-- notion outbox and lead-linked calendar rows first, then n8n's chat memory
-- (session_id = lead id), then leads, then the debounce buffer.
-- Returns per-table deleted counts. Destructive and not reversible.
CREATE OR REPLACE FUNCTION reset_leads(p_artist_slug TEXT DEFAULT NULL)
RETURNS JSONB AS $$
DECLARE
  v_artist_id  UUID;
  v_events     INTEGER := 0;
  v_outbox     INTEGER := 0;
  v_calendar   INTEGER := 0;
  v_memory     INTEGER := 0;
  v_leads      INTEGER := 0;
  v_buffer     INTEGER := 0;
  v_has_memory BOOLEAN := to_regclass('public.chat_memory') IS NOT NULL;
BEGIN
  IF p_artist_slug IS NULL THEN
    DELETE FROM events;
    GET DIAGNOSTICS v_events = ROW_COUNT;

    DELETE FROM notion_sync_outbox;
    GET DIAGNOSTICS v_outbox = ROW_COUNT;

    DELETE FROM calendar WHERE lead_id IS NOT NULL;
    GET DIAGNOSTICS v_calendar = ROW_COUNT;

    IF v_has_memory THEN
      EXECUTE 'DELETE FROM chat_memory WHERE session_id IN (SELECT id::text FROM leads)';
      GET DIAGNOSTICS v_memory = ROW_COUNT;
    END IF;

    DELETE FROM leads;
    GET DIAGNOSTICS v_leads = ROW_COUNT;

    DELETE FROM message_buffer;
    GET DIAGNOSTICS v_buffer = ROW_COUNT;

    DELETE FROM chat_control;
  ELSE
    SELECT id INTO v_artist_id FROM artists WHERE wa_session_slug = p_artist_slug;
    IF v_artist_id IS NULL THEN
      RAISE EXCEPTION 'reset_leads: no artist with wa_session_slug=%', p_artist_slug;
    END IF;

    DELETE FROM events WHERE artist_id = v_artist_id;
    GET DIAGNOSTICS v_events = ROW_COUNT;

    DELETE FROM notion_sync_outbox WHERE artist_id = v_artist_id;
    GET DIAGNOSTICS v_outbox = ROW_COUNT;

    DELETE FROM calendar WHERE artist_id = v_artist_id AND lead_id IS NOT NULL;
    GET DIAGNOSTICS v_calendar = ROW_COUNT;

    IF v_has_memory THEN
      EXECUTE 'DELETE FROM chat_memory WHERE session_id IN (SELECT id::text FROM leads WHERE artist_id = $1)' USING v_artist_id;
      GET DIAGNOSTICS v_memory = ROW_COUNT;
    END IF;

    DELETE FROM leads WHERE artist_id = v_artist_id;
    GET DIAGNOSTICS v_leads = ROW_COUNT;

    DELETE FROM message_buffer WHERE chat_id LIKE p_artist_slug || ':%';
    GET DIAGNOSTICS v_buffer = ROW_COUNT;

    DELETE FROM chat_control WHERE artist_id = v_artist_id;
  END IF;

  RETURN jsonb_build_object(
    'artist_slug', p_artist_slug,
    'leads', v_leads,
    'events', v_events,
    'calendar', v_calendar,
    'notion_outbox', v_outbox,
    'chat_memory', v_memory,
    'message_buffer', v_buffer
  );
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- Offers (promotions) + returning-customer segment + per-chat ownership
-- Added 2026-10-22. Promotion mechanic is centrally fixed; a VAIF admin owns
-- per-artist price (multiplier on the regular pricing matrix), conditions and
-- activation. One ACTIVE promotion per artist. Prices snapshotted at
-- activation. A quote's promotion price is protected 7 days from when sent.
-- "Returning" = prior confirmed booking with the SAME artist. Artist takes a
-- chat over by replying in WhatsApp; ownership persists until returned.
-- ============================================================

CREATE TABLE IF NOT EXISTS promotion_types (
  key         TEXT PRIMARY KEY,
  name        TEXT NOT NULL,
  description TEXT,
  config_spec JSONB NOT NULL DEFAULT '{}'::jsonb,
  active      BOOLEAN NOT NULL DEFAULT true,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO promotion_types (key, name, description, config_spec)
VALUES (
  'bogo_same_value',
  'Leve 2 pelo valor de {multiplier}x',
  'Compre 1 tatuagem e leve outra de mesmo valor ou menor. Segunda tatuagem pode ser de outra pessoa e em outro local do corpo; o artista agenda a segunda depois. Excecoes vao para o artista.',
  '{"multiplier": 2.0, "quote_valid_days": 7, "second_recipient_allowed": true, "second_placement_free": true}'::jsonb
)
ON CONFLICT (key) DO NOTHING;

CREATE TABLE IF NOT EXISTS promotions (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id         UUID NOT NULL REFERENCES artists(id),
  type_key          TEXT NOT NULL REFERENCES promotion_types(key),
  name              TEXT NOT NULL,
  multiplier        NUMERIC(4,2) NOT NULL DEFAULT 2.0,
  audience          TEXT NOT NULL DEFAULT 'all'
    CHECK (audience IN ('all','new','returning')),
  conditions        JSONB NOT NULL DEFAULT '{}'::jsonb,
  quote_valid_days  INTEGER NOT NULL DEFAULT 7,
  status            TEXT NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','active','paused','ended')),
  activated_at      TIMESTAMPTZ,
  paused_at         TIMESTAMPTZ,
  ended_at          TIMESTAMPTZ,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS one_active_promotion_per_artist
  ON promotions (artist_id) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_promotions_artist ON promotions (artist_id, status);

CREATE TABLE IF NOT EXISTS promotion_prices (
  promotion_id  UUID NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
  placement     TEXT NOT NULL,
  body_zone     TEXT NOT NULL,
  base_price    INTEGER NOT NULL,
  offer_price   INTEGER NOT NULL,
  PRIMARY KEY (promotion_id, placement, body_zone)
);

CREATE TABLE IF NOT EXISTS promotion_quotes (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  promotion_id     UUID NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
  lead_id          UUID NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  artist_id        UUID NOT NULL REFERENCES artists(id),
  placement        TEXT NOT NULL,
  body_zone        TEXT NOT NULL,
  first_price      INTEGER NOT NULL,
  second_cap_price INTEGER NOT NULL,
  quoted_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  valid_until      TIMESTAMPTZ NOT NULL,
  status           TEXT NOT NULL DEFAULT 'open'
    CHECK (status IN ('open','secured','lapsed','void')),
  secured_at       TIMESTAMPTZ,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_promo_quotes_lead ON promotion_quotes (lead_id, status);
CREATE INDEX IF NOT EXISTS idx_promo_quotes_promo ON promotion_quotes (promotion_id, status);

CREATE TABLE IF NOT EXISTS returning_customers (
  artist_id    UUID NOT NULL REFERENCES artists(id),
  telefone     TEXT NOT NULL,
  nome         TEXT,
  source       TEXT NOT NULL DEFAULT 'imported'
    CHECK (source IN ('imported','deposit_confirmed')),
  confirmed_at TIMESTAMPTZ,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (artist_id, telefone)
);

CREATE TABLE IF NOT EXISTS chat_control (
  chat_id    TEXT PRIMARY KEY,
  artist_id  UUID NOT NULL REFERENCES artists(id),
  owner      TEXT NOT NULL DEFAULT 'beatriz'
    CHECK (owner IN ('beatriz','artist')),
  taken_at   TIMESTAMPTZ,
  returned_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION activate_promotion(
  p_promotion_id UUID
) RETURNS SETOF promotions AS $$
DECLARE
  v_promo promotions;
BEGIN
  SELECT * INTO v_promo FROM promotions WHERE id = p_promotion_id;
  IF NOT FOUND THEN RETURN; END IF;
  IF v_promo.status NOT IN ('draft','paused') THEN RETURN; END IF;

  DELETE FROM promotion_prices WHERE promotion_id = p_promotion_id;
  INSERT INTO promotion_prices (promotion_id, placement, body_zone, base_price, offer_price)
  SELECT p_promotion_id, p.placement, p.body_zone, p.table_price,
         CEIL(p.table_price * v_promo.multiplier)::integer
  FROM pricing p
  WHERE p.artist_id = v_promo.artist_id AND p.active = true;

  UPDATE promotions SET
    status = 'active', activated_at = now(), paused_at = NULL, updated_at = now()
  WHERE id = p_promotion_id
  RETURNING * INTO v_promo;

  RETURN NEXT v_promo;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION pause_promotion(
  p_promotion_id UUID
) RETURNS SETOF promotions AS $$
DECLARE
  v_promo promotions;
BEGIN
  UPDATE promotions SET
    status = 'paused', paused_at = now(), updated_at = now()
  WHERE id = p_promotion_id AND status = 'active'
  RETURNING * INTO v_promo;
  IF v_promo.id IS NOT NULL THEN RETURN NEXT v_promo; END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION end_promotion(
  p_promotion_id UUID
) RETURNS SETOF promotions AS $$
DECLARE
  v_promo promotions;
BEGIN
  UPDATE promotions SET
    status = 'ended', ended_at = now(), updated_at = now()
  WHERE id = p_promotion_id AND status IN ('active','paused')
  RETURNING * INTO v_promo;
  IF v_promo.id IS NOT NULL THEN
    UPDATE promotion_quotes SET status = 'void'
    WHERE promotion_id = p_promotion_id AND status = 'open';
    RETURN NEXT v_promo;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION is_returning_customer(
  p_artist_id UUID,
  p_telefone TEXT
) RETURNS BOOLEAN AS $$
BEGIN
  IF p_telefone IS NULL THEN RETURN false; END IF;
  RETURN EXISTS (
    SELECT 1 FROM returning_customers
    WHERE artist_id = p_artist_id AND telefone = p_telefone
  ) OR EXISTS (
    SELECT 1 FROM leads
    WHERE artist_id = p_artist_id
      AND telefone = p_telefone
      AND deposit_status = 'confirmado'
  );
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

CREATE OR REPLACE FUNCTION active_offer_for_lead(
  p_artist_id UUID,
  p_lead_id UUID
) RETURNS TABLE(
  promotion_id UUID,
  name TEXT,
  description TEXT,
  multiplier NUMERIC,
  quote_valid_days INTEGER,
  conditions JSONB,
  promo_active BOOLEAN,
  open_quote_id UUID,
  open_quote_valid_until TIMESTAMPTZ,
  open_quote_placement TEXT,
  open_quote_body_zone TEXT,
  open_quote_first_price INTEGER,
  open_quote_second_cap INTEGER,
  open_quote_valid BOOLEAN
) AS $$
DECLARE
  v_segment TEXT;
BEGIN
  v_segment := CASE WHEN is_returning_customer(p_artist_id, (SELECT telefone FROM leads WHERE id = p_lead_id))
                    THEN 'returning' ELSE 'new' END;

  RETURN QUERY
  SELECT
    p.id, p.name,
    (SELECT t.description FROM promotion_types t WHERE t.key = p.type_key),
    p.multiplier, p.quote_valid_days, p.conditions,
    p.id IS NOT NULL,
    q.id, q.valid_until, q.placement, q.body_zone, q.first_price, q.second_cap_price,
    q.id IS NOT NULL
  FROM
    (SELECT * FROM promotions
     WHERE artist_id = p_artist_id
       AND status = 'active'
       AND (audience = 'all' OR audience = v_segment)
     LIMIT 1) p
  FULL OUTER JOIN
    (SELECT * FROM promotion_quotes
     WHERE lead_id = p_lead_id
       AND status = 'open'
       AND valid_until > now()
     ORDER BY quoted_at DESC
     LIMIT 1) q
    ON true
  UNION ALL
  SELECT
    NULL::uuid, NULL::text, NULL::text, NULL::numeric, NULL::integer, NULL::jsonb,
    false, NULL::uuid, NULL::timestamptz, NULL::text, NULL::text,
    NULL::integer, NULL::integer, false
  WHERE NOT EXISTS (
    SELECT 1 FROM promotions
    WHERE artist_id = p_artist_id AND status = 'active'
      AND (audience = 'all' OR audience = v_segment)
  )
  AND NOT EXISTS (
    SELECT 1 FROM promotion_quotes
    WHERE lead_id = p_lead_id AND status = 'open' AND valid_until > now()
  )
  LIMIT 1;
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;

CREATE OR REPLACE FUNCTION write_promotion_quote(
  p_lead_id UUID,
  p_promotion_id UUID,
  p_placement TEXT,
  p_body_zone TEXT
) RETURNS SETOF promotion_quotes AS $$
DECLARE
  v_promo promotions;
  v_price promotion_prices;
  v_quote promotion_quotes;
BEGIN
  SELECT * INTO v_promo FROM promotions WHERE id = p_promotion_id;
  IF NOT FOUND THEN RETURN; END IF;

  SELECT * INTO v_quote FROM promotion_quotes
  WHERE lead_id = p_lead_id
    AND promotion_id = p_promotion_id
    AND status IN ('open', 'secured')
  ORDER BY quoted_at DESC
  LIMIT 1;
  IF FOUND THEN
    RETURN NEXT v_quote;
    RETURN;
  END IF;

  SELECT * INTO v_price FROM promotion_prices
  WHERE promotion_id = p_promotion_id
    AND placement = p_placement AND body_zone = p_body_zone;
  IF NOT FOUND THEN RETURN; END IF;

  INSERT INTO promotion_quotes (
    promotion_id, lead_id, artist_id, placement, body_zone,
    first_price, second_cap_price, valid_until
  ) VALUES (
    p_promotion_id, p_lead_id, v_promo.artist_id, p_placement, p_body_zone,
    v_price.offer_price, v_price.base_price,
    now() + make_interval(days => GREATEST(v_promo.quote_valid_days, 1))
  ) RETURNING * INTO v_quote;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_promo.artist_id, 'promotion_quote_sent',
          jsonb_build_object(
            'promotion_id', p_promotion_id,
            'promotion', v_promo.name,
            'placement', p_placement,
            'body_zone', p_body_zone,
            'first_price', v_price.offer_price,
            'second_cap_price', v_price.base_price,
            'valid_until', v_quote.valid_until
          ));

  RETURN NEXT v_quote;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION import_returning_customer(
  p_artist_id UUID,
  p_telefone TEXT,
  p_nome TEXT DEFAULT NULL,
  p_confirmed_at TIMESTAMPTZ DEFAULT NULL
) RETURNS SETOF returning_customers AS $$
DECLARE
  v_row returning_customers;
BEGIN
  INSERT INTO returning_customers (artist_id, telefone, nome, source, confirmed_at)
  VALUES (p_artist_id, p_telefone, p_nome, 'imported', COALESCE(p_confirmed_at, now()))
  ON CONFLICT (artist_id, telefone) DO UPDATE SET
    nome = COALESCE(EXCLUDED.nome, returning_customers.nome),
    confirmed_at = COALESCE(EXCLUDED.confirmed_at, returning_customers.confirmed_at)
  RETURNING * INTO v_row;
  RETURN NEXT v_row;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION take_chat_control(
  p_chat_id TEXT,
  p_artist_id UUID
) RETURNS SETOF chat_control AS $$
DECLARE
  v_row chat_control;
BEGIN
  INSERT INTO chat_control (chat_id, artist_id, owner, taken_at, returned_at)
  VALUES (p_chat_id, p_artist_id, 'artist', now(), NULL)
  ON CONFLICT (chat_id) DO UPDATE SET
    owner = 'artist', taken_at = now(), returned_at = NULL, updated_at = now()
  RETURNING * INTO v_row;

  UPDATE leads SET updated_at = now()
  WHERE artist_id = p_artist_id
    AND telefone = split_part(p_chat_id, ':', 2);

  RETURN NEXT v_row;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION return_chat_control(
  p_chat_id TEXT,
  p_artist_id UUID
) RETURNS SETOF chat_control AS $$
DECLARE
  v_row chat_control;
BEGIN
  UPDATE chat_control SET
    owner = 'beatriz', returned_at = now(), updated_at = now()
  WHERE chat_id = p_chat_id AND artist_id = p_artist_id
  RETURNING * INTO v_row;
  IF v_row.chat_id IS NOT NULL THEN RETURN NEXT v_row; END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION chat_owner(
  p_chat_id TEXT
) RETURNS TEXT AS $$
  SELECT COALESCE((SELECT owner FROM chat_control WHERE chat_id = p_chat_id), 'beatriz');
$$ LANGUAGE sql STABLE SECURITY DEFINER;

-- confirm_deposit: a confirmed deposit (a) marks the phone as a returning
-- customer for that artist, (b) secures any open promotion quote on the lead.
CREATE OR REPLACE FUNCTION confirm_deposit(
  p_lead_id UUID
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    deposit_status = 'confirmado',
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'deposit_confirmed', '{}'::jsonb);

  INSERT INTO returning_customers (artist_id, telefone, nome, source, confirmed_at)
  SELECT v_artist_id, l.telefone, l.nome, 'deposit_confirmed', now()
  FROM leads l WHERE l.id = p_lead_id AND l.telefone IS NOT NULL
  ON CONFLICT (artist_id, telefone) DO UPDATE SET
    confirmed_at = EXCLUDED.confirmed_at,
    source = CASE
      WHEN returning_customers.source = 'imported' THEN returning_customers.source
      ELSE 'deposit_confirmed'
    END;

  UPDATE promotion_quotes SET status = 'secured', secured_at = now()
  WHERE lead_id = p_lead_id AND status = 'open';

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- ============================================================
-- Reconcile columns the CRM writes but no earlier migration created.
-- Confirmed missing from the deployed schema on 2026-10-28; without these the
-- Settings screen fails with SQLSTATE 42703. Kept at the end so the generated
-- n8n migration workflow only appends nodes.
-- ============================================================
ALTER TABLE artists ADD COLUMN IF NOT EXISTS pricing_extras JSONB;
ALTER TABLE pricing ADD COLUMN IF NOT EXISTS region TEXT;

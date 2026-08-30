-- VAIF SDR Closer — CRUD write-contract functions
-- Implements the 13 CRUD + 3 RO operations from design/crm-write-contract.md
-- Also adds artist-onboarding helpers for #6/#8

-- ============================================================
-- 1. create_lead(artist_id UUID, telefone TEXT, nome TEXT DEFAULT NULL)
--    → RETURNS leads row
--    Trigger: inbound message from unknown number
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


-- ============================================================
-- 2. load_lead(telefone TEXT, artist_id UUID)
--    → RETURNS leads row or empty set
--    Trigger: every inbound message
-- ============================================================
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


-- ============================================================
-- 3. update_qualification(lead_id UUID, fields JSONB)
--    → RETURNS leads row
--    Trigger: each discovery answer
--    fields: {"placement": "...", "body_zone": "...", "style": "..."}
-- ============================================================
CREATE OR REPLACE FUNCTION update_qualification(
  p_lead_id UUID,
  p_fields JSONB
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  -- Dynamically update only the keys present in p_fields
  IF p_fields ? 'nome' THEN
    UPDATE leads SET nome = p_fields->>'nome' WHERE id = p_lead_id;
  END IF;
  IF p_fields ? 'placement' THEN
    UPDATE leads SET placement = p_fields->>'placement' WHERE id = p_lead_id;
  END IF;
  IF p_fields ? 'body_zone' THEN
    UPDATE leads SET body_zone = p_fields->>'body_zone' WHERE id = p_lead_id;
  END IF;
  IF p_fields ? 'style' THEN
    UPDATE leads SET style = p_fields->>'style' WHERE id = p_lead_id;
  END IF;
  IF p_fields ? 'primeira_tatuagem' THEN
    UPDATE leads SET primeira_tatuagem = (p_fields->>'primeira_tatuagem')::boolean WHERE id = p_lead_id;
  END IF;
  IF p_fields ? 'significado' THEN
    UPDATE leads SET significado = p_fields->>'significado' WHERE id = p_lead_id;
  END IF;
  IF p_fields ? 'lead_source' THEN
    UPDATE leads SET lead_source = p_fields->>'lead_source' WHERE id = p_lead_id;
  END IF;

  UPDATE leads SET updated_at = now(), last_message_at = now() WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'qualification_updated', p_fields);

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 4. mark_pipeline_state(lead_id UUID, new_state TEXT)
--    → RETURNS void
--    Trigger: phase transitions
-- ============================================================
CREATE OR REPLACE FUNCTION mark_pipeline_state(
  p_lead_id UUID,
  p_new_state TEXT
) RETURNS VOID AS $$
DECLARE
  v_artist_id UUID;
  v_old_state TEXT;
BEGIN
  SELECT artist_id, pipeline_status INTO v_artist_id, v_old_state
  FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    pipeline_status = p_new_state,
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'pipeline_state_changed',
          jsonb_build_object('from', v_old_state, 'to', p_new_state));
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 5. lookup_price(placement TEXT, body_zone TEXT, artist_id UUID)
--    → RETURNS pricing row or empty set
--    Trigger: after doubt-clearing, before quoting
-- ============================================================
CREATE OR REPLACE FUNCTION lookup_price(
  p_placement TEXT,
  p_body_zone TEXT,
  p_artist_id UUID
) RETURNS SETOF pricing AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM pricing
    WHERE artist_id = p_artist_id
      AND placement = p_placement
      AND body_zone = p_body_zone
      AND active = true
    LIMIT 1;
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;


-- ============================================================
-- 6. write_quote(lead_id UUID, table_price INTEGER, negotiated_price INTEGER DEFAULT NULL)
--    → RETURNS leads row
--    Trigger: Beatriz presents price
-- ============================================================
CREATE OR REPLACE FUNCTION write_quote(
  p_lead_id UUID,
  p_table_price INTEGER,
  p_negotiated_price INTEGER DEFAULT NULL
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    table_price = p_table_price,
    negotiated_price = COALESCE(p_negotiated_price, p_table_price),
    pipeline_status = 'orcamento_enviado',
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'quote_sent',
          jsonb_build_object(
            'table_price', p_table_price,
            'negotiated_price', COALESCE(p_negotiated_price, p_table_price)
          ));

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 7. request_deposit(lead_id UUID, amount INTEGER)
--    → RETURNS leads row
--    Trigger: lead accepts price
-- ============================================================
CREATE OR REPLACE FUNCTION request_deposit(
  p_lead_id UUID,
  p_amount INTEGER
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    deposit_amount = p_amount,
    deposit_status = 'aguardando_confirmacao',
    pipeline_status = 'aguardando_deposito',
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'deposit_requested',
          jsonb_build_object('amount', p_amount));

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 8. confirm_deposit(lead_id UUID)
--    → RETURNS leads row
--    Trigger: artist confirms receipt (human action)
-- ============================================================
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

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 9. check_availability(artist_id UUID, from_date TIMESTAMPTZ, to_date TIMESTAMPTZ, duration_min INTEGER DEFAULT 60)
--    → RETURNS derived bookable slots
--    Availability is DERIVED from the artist's weekly working hours (a JSONB
--    object keyed by weekday seg/ter/qua/qui/sex/sab/dom with "HH:MM-HH:MM"
--    block arrays), interpreted in the artist's own timezone (artists.timezone,
--    not UTC). Slots are hour-aligned, sized to the requested duration, and free
--    unless a 'booked' or 'blocked' calendar row overlaps them. Legacy
--    'available' rows are ignored entirely.
--    Trigger: after deposit confirmed, before booking
-- ============================================================
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


-- ============================================================
-- 10. book_slot(lead_id UUID, start_at TIMESTAMPTZ, duration_min INTEGER, buffer_min INTEGER)
--     → RETURNS leads row
--     Trigger: slot chosen and confirmed
-- ============================================================
CREATE OR REPLACE FUNCTION book_slot(
  p_lead_id UUID,
  p_start_at TIMESTAMPTZ,
  p_duration_min INTEGER,
  p_buffer_min INTEGER DEFAULT 30
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
  v_end_at TIMESTAMPTZ;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  v_end_at := p_start_at + (p_duration_min + p_buffer_min) * INTERVAL '1 minute';

  -- Reject (don't insert) when the chosen window overlaps an existing
  -- 'booked' or 'blocked' period for the same artist — no double-booking.
  IF EXISTS (
    SELECT 1 FROM calendar c
    WHERE c.artist_id = v_artist_id
      AND c.type IN ('booked', 'blocked')
      AND c.start_at < v_end_at
      AND c.end_at > p_start_at
  ) THEN
    RETURN;
  END IF;

  -- Mark the calendar block as booked
  INSERT INTO calendar (artist_id, start_at, end_at, type, lead_id)
  VALUES (v_artist_id, p_start_at, v_end_at, 'booked', p_lead_id);

  -- Update the lead
  UPDATE leads SET
    booked_date = p_start_at,
    session_duration_min = p_duration_min,
    buffer_min = p_buffer_min,
    pipeline_status = 'agendado',
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'slot_booked',
          jsonb_build_object(
            'start_at', p_start_at,
            'duration_min', p_duration_min,
            'buffer_min', p_buffer_min
          ));

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 10b. block_slot(artist_id UUID, start_at TIMESTAMPTZ, end_at TIMESTAMPTZ)
--      → RETURNS calendar row (type='blocked')
--      Trigger: artist/admin marks an off-day or personal appointment
-- ============================================================
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


-- ============================================================
-- 10c. unblock_slot(block_id UUID)
--      → RETURNS the removed calendar row (or empty set)
--      Trigger: artist/admin reopens a blocked period
-- ============================================================
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


-- ============================================================
-- 11. mark_handoff(lead_id UUID, reason TEXT)
--     → RETURNS leads row
--     Trigger: any handoff trigger fires
-- ============================================================
CREATE OR REPLACE FUNCTION mark_handoff(
  p_lead_id UUID,
  p_reason TEXT
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    pipeline_status = 'aguardando_artista',
    handoff_reason = p_reason,
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'handoff_triggered',
          jsonb_build_object('reason', p_reason));

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 12. close_won(lead_id UUID)
--     → RETURNS leads row
-- ============================================================
CREATE OR REPLACE FUNCTION close_won(
  p_lead_id UUID
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    pipeline_status = 'fechado',
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'pipeline_state_changed',
          jsonb_build_object('to', 'fechado'));

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- 13. close_lost(lead_id UUID)
--     → RETURNS leads row
-- ============================================================
CREATE OR REPLACE FUNCTION close_lost(
  p_lead_id UUID
) RETURNS SETOF leads AS $$
DECLARE
  v_lead leads;
  v_artist_id UUID;
BEGIN
  SELECT artist_id INTO v_artist_id FROM leads WHERE id = p_lead_id;

  UPDATE leads SET
    pipeline_status = 'perdido',
    updated_at = now()
  WHERE id = p_lead_id;

  INSERT INTO events (lead_id, artist_id, event_type, payload)
  VALUES (p_lead_id, v_artist_id, 'pipeline_state_changed',
          jsonb_build_object('to', 'perdido'));

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;


-- ============================================================
-- R1. load_artist_config(artist_id UUID)
--     → RETURNS artists row
-- ============================================================
CREATE OR REPLACE FUNCTION load_artist_config(
  p_artist_id UUID
) RETURNS SETOF artists AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM artists WHERE id = p_artist_id;
END;
$$ LANGUAGE plpgsql STABLE SECURITY DEFINER;


-- ============================================================
-- Helper: resolve artist from WAHA session slug
-- Used by #3 (WhatsApp agent) — maps session name to artist
-- ============================================================
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


-- ============================================================
-- Artist onboarding helpers (#6, #8)
-- ============================================================

-- Validate onboarding token (checks existence, not consumed, not expired)
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


-- Consume onboarding token: activate artist, nullify token
CREATE OR REPLACE FUNCTION consume_onboarding_token(
  p_token TEXT
) RETURNS SETOF artists AS $$
DECLARE
  v_artist artists;
BEGIN
  UPDATE artists SET
    status = 'onboarding',
    onboarding_token = NULL,
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


-- Transition artist from onboarding to live
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

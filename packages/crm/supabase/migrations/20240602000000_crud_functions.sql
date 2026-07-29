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
-- 9. check_availability(artist_id UUID, from_date TIMESTAMPTZ, to_date TIMESTAMPTZ, duration_min INTEGER)
--    → RETURNS available calendar rows
--    Trigger: after deposit confirmed, before booking
-- ============================================================
CREATE OR REPLACE FUNCTION check_availability(
  p_artist_id UUID,
  p_from_date TIMESTAMPTZ,
  p_to_date TIMESTAMPTZ,
  p_duration_min INTEGER
) RETURNS SETOF calendar AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM calendar
    WHERE artist_id = p_artist_id
      AND type = 'available'
      AND start_at >= p_from_date
      AND end_at <= p_to_date
      AND EXTRACT(EPOCH FROM (end_at - start_at)) / 60 >= p_duration_min
    ORDER BY start_at;
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

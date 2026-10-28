--
-- PostgreSQL database dump
--

\restrict i8NA3cxPktJrdEksvcmypAqoFyv6Pw0vYBdhuYHbx4Es47Et1wd8BfJh5cXtzLl

-- Dumped from database version 17.7
-- Dumped by pg_dump version 17.7

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: promotions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.promotions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    artist_id uuid NOT NULL,
    type_key text NOT NULL,
    name text NOT NULL,
    multiplier numeric(4,2) DEFAULT 2.0 NOT NULL,
    audience text DEFAULT 'all'::text NOT NULL,
    conditions jsonb DEFAULT '{}'::jsonb NOT NULL,
    quote_valid_days integer DEFAULT 7 NOT NULL,
    status text DEFAULT 'draft'::text NOT NULL,
    activated_at timestamp with time zone,
    paused_at timestamp with time zone,
    ended_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT promotions_audience_check CHECK ((audience = ANY (ARRAY['all'::text, 'new'::text, 'returning'::text]))),
    CONSTRAINT promotions_status_check CHECK ((status = ANY (ARRAY['draft'::text, 'active'::text, 'paused'::text, 'ended'::text])))
);


ALTER TABLE public.promotions OWNER TO postgres;

--
-- Name: activate_promotion(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.activate_promotion(p_promotion_id uuid) RETURNS SETOF public.promotions
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_promo promotions;
BEGIN
  SELECT * INTO v_promo FROM promotions WHERE id = p_promotion_id;
  IF NOT FOUND THEN RETURN; END IF;
  IF v_promo.status NOT IN ('draft','paused') THEN RETURN; END IF;

  -- Fresh snapshot on every activation: current regular matrix x multiplier.
  DELETE FROM promotion_prices WHERE promotion_id = p_promotion_id;
  INSERT INTO promotion_prices (promotion_id, placement, body_zone, base_price, offer_price)
  SELECT p_promotion_id, p.placement, p.body_zone, p.table_price,
         CEIL(p.table_price * v_promo.multiplier)::integer
  FROM pricing p
  WHERE p.artist_id = v_promo.artist_id AND p.active = true;

  UPDATE promotions SET
    status = 'active',
    activated_at = now(),
    paused_at = NULL,
    updated_at = now()
  WHERE id = p_promotion_id
  RETURNING * INTO v_promo;

  RETURN NEXT v_promo;
END;
$$;


ALTER FUNCTION public.activate_promotion(p_promotion_id uuid) OWNER TO postgres;

--
-- Name: active_offer_for_lead(uuid, uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.active_offer_for_lead(p_artist_id uuid, p_lead_id uuid) RETURNS TABLE(promotion_id uuid, name text, description text, multiplier numeric, quote_valid_days integer, conditions jsonb, promo_active boolean, open_quote_id uuid, open_quote_valid_until timestamp with time zone, open_quote_placement text, open_quote_body_zone text, open_quote_first_price integer, open_quote_second_cap integer, open_quote_valid boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.active_offer_for_lead(p_artist_id uuid, p_lead_id uuid) OWNER TO postgres;

--
-- Name: calendar; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.calendar (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    artist_id uuid NOT NULL,
    start_at timestamp with time zone NOT NULL,
    end_at timestamp with time zone NOT NULL,
    type text DEFAULT 'available'::text NOT NULL,
    lead_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.calendar OWNER TO postgres;

--
-- Name: block_slot(uuid, timestamp with time zone, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.block_slot(p_artist_id uuid, p_start_at timestamp with time zone, p_end_at timestamp with time zone) RETURNS SETOF public.calendar
    LANGUAGE plpgsql
    AS $$
DECLARE
  v_block calendar;
BEGIN
  INSERT INTO calendar (artist_id, start_at, end_at, type)
  VALUES (p_artist_id, p_start_at, p_end_at, 'blocked')
  RETURNING * INTO v_block;
  RETURN NEXT v_block;
END;
$$;


ALTER FUNCTION public.block_slot(p_artist_id uuid, p_start_at timestamp with time zone, p_end_at timestamp with time zone) OWNER TO postgres;

--
-- Name: leads; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.leads (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    artist_id uuid NOT NULL,
    nome text,
    telefone text NOT NULL,
    lead_source text,
    placement text,
    body_zone text,
    style text,
    primeira_tatuagem boolean,
    significado text,
    reference_pics text[],
    table_price integer,
    negotiated_price integer,
    discount_percent numeric(5,2),
    contrapartida text DEFAULT 'Instagram post + fechando agora'::text,
    booked_date timestamp with time zone,
    session_duration_min integer,
    buffer_min integer,
    deposit_amount integer,
    deposit_status text DEFAULT 'nao_solicitado'::text,
    pipeline_status text DEFAULT 'novo'::text NOT NULL,
    handoff_reason text,
    conversation_started timestamp with time zone DEFAULT now() NOT NULL,
    last_message_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    notion_sync_status text DEFAULT 'pending'::text,
    notion_synced_at timestamp with time zone,
    tipo_tatuagem text,
    processo_explicado boolean DEFAULT false,
    preco_liberado boolean DEFAULT false,
    duvidas_eliminadas boolean DEFAULT false,
    audio_count integer DEFAULT 0,
    aguardando_confirmacao_duvidas boolean DEFAULT false,
    CONSTRAINT leads_notion_sync_status_check CHECK ((notion_sync_status = ANY (ARRAY['pending'::text, 'synced'::text, 'failed'::text])))
);


ALTER TABLE public.leads OWNER TO postgres;

--
-- Name: book_slot(uuid, timestamp with time zone, integer, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.book_slot(p_lead_id uuid, p_start_at timestamp with time zone, p_duration_min integer, p_buffer_min integer DEFAULT 30) RETURNS SETOF public.leads
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.book_slot(p_lead_id uuid, p_start_at timestamp with time zone, p_duration_min integer, p_buffer_min integer) OWNER TO postgres;

--
-- Name: book_slot_checked(uuid, timestamp with time zone, integer, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.book_slot_checked(p_lead_id uuid, p_start_at timestamp with time zone, p_duration_min integer DEFAULT 120, p_buffer_min integer DEFAULT 30) RETURNS SETOF public.leads
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.book_slot_checked(p_lead_id uuid, p_start_at timestamp with time zone, p_duration_min integer, p_buffer_min integer) OWNER TO postgres;

--
-- Name: chat_owner(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.chat_owner(p_chat_id text) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER
    AS $$
  SELECT COALESCE((SELECT owner FROM chat_control WHERE chat_id = p_chat_id), 'beatriz');
$$;


ALTER FUNCTION public.chat_owner(p_chat_id text) OWNER TO postgres;

--
-- Name: check_availability(uuid, timestamp with time zone, timestamp with time zone, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.check_availability(p_artist_id uuid, p_from_date timestamp with time zone, p_to_date timestamp with time zone, p_duration_min integer DEFAULT 60) RETURNS TABLE(id uuid, start_at timestamp with time zone, end_at timestamp with time zone, type text)
    LANGUAGE plpgsql STABLE
    AS $$
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
$$;


ALTER FUNCTION public.check_availability(p_artist_id uuid, p_from_date timestamp with time zone, p_to_date timestamp with time zone, p_duration_min integer) OWNER TO postgres;

--
-- Name: notion_sync_outbox; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.notion_sync_outbox (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lead_id uuid NOT NULL,
    artist_id uuid NOT NULL,
    payload jsonb NOT NULL,
    status text DEFAULT 'pending'::text NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    next_attempt_at timestamp with time zone DEFAULT now() NOT NULL,
    last_error text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    processed_at timestamp with time zone,
    CONSTRAINT notion_sync_outbox_status_check CHECK ((status = ANY (ARRAY['pending'::text, 'processing'::text, 'done'::text, 'failed'::text])))
);


ALTER TABLE public.notion_sync_outbox OWNER TO postgres;

--
-- Name: claim_notion_sync_rows(integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.claim_notion_sync_rows(p_limit integer DEFAULT 50) RETURNS SETOF public.notion_sync_outbox
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.claim_notion_sync_rows(p_limit integer) OWNER TO postgres;

--
-- Name: artists; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.artists (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    display_name text NOT NULL,
    notion_database_id text,
    notion_api_key text,
    telegram_bot_token text,
    pix_key text,
    studio_address text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    telegram_group_id text,
    notion_token text,
    notion_clientes_database_id text,
    notion_projects_database_id text,
    nome text,
    specialties text[],
    nao_faco text[],
    floor_pct numeric(5,2),
    deposit_type text DEFAULT 'percent'::text,
    deposit_value integer,
    instagram_handle text,
    working_hours jsonb,
    wa_session_slug text,
    status text DEFAULT 'stub'::text NOT NULL,
    onboarding_token text,
    whatsapp_number text,
    ai_active_hours jsonb,
    timezone text
);


ALTER TABLE public.artists OWNER TO postgres;

--
-- Name: COLUMN artists.notion_token; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.artists.notion_token IS 'Notion internal integration secret for this artist workspace';


--
-- Name: COLUMN artists.notion_clientes_database_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.artists.notion_clientes_database_id IS 'UUID of the Clientes data source in this artist workspace';


--
-- Name: COLUMN artists.notion_projects_database_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.artists.notion_projects_database_id IS 'UUID of the Projects data source in this artist workspace';


--
-- Name: complete_artist_onboarding(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.complete_artist_onboarding(p_artist_id uuid) RETURNS SETOF public.artists
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.complete_artist_onboarding(p_artist_id uuid) OWNER TO postgres;

--
-- Name: complete_notion_sync_row(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.complete_notion_sync_row(p_id uuid) RETURNS void
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.complete_notion_sync_row(p_id uuid) OWNER TO postgres;

--
-- Name: confirm_deposit(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.confirm_deposit(p_lead_id uuid) RETURNS SETOF public.leads
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
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

  -- Returning-customer roll-up (same artist, same phone).
  INSERT INTO returning_customers (artist_id, telefone, nome, source, confirmed_at)
  SELECT v_artist_id, l.telefone, l.nome, 'deposit_confirmed', now()
  FROM leads l WHERE l.id = p_lead_id AND l.telefone IS NOT NULL
  ON CONFLICT (artist_id, telefone) DO UPDATE SET
    confirmed_at = EXCLUDED.confirmed_at,
    source = CASE
      WHEN returning_customers.source = 'imported' THEN returning_customers.source
      ELSE 'deposit_confirmed'
    END;

  -- Secure the second-tattoo benefit: the quote survives pause/end from here.
  UPDATE promotion_quotes SET status = 'secured', secured_at = now()
  WHERE lead_id = p_lead_id AND status = 'open';

  SELECT * INTO v_lead FROM leads WHERE id = p_lead_id;
  RETURN NEXT v_lead;
END;
$$;


ALTER FUNCTION public.confirm_deposit(p_lead_id uuid) OWNER TO postgres;

--
-- Name: consume_onboarding_token(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.consume_onboarding_token(p_token text) RETURNS SETOF public.artists
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.consume_onboarding_token(p_token text) OWNER TO postgres;

--
-- Name: end_promotion(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.end_promotion(p_promotion_id uuid) RETURNS SETOF public.promotions
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_promo promotions;
BEGIN
  UPDATE promotions SET
    status = 'ended',
    ended_at = now(),
    updated_at = now()
  WHERE id = p_promotion_id AND status IN ('active','paused')
  RETURNING * INTO v_promo;
  IF v_promo.id IS NOT NULL THEN
    UPDATE promotion_quotes SET status = 'void'
    WHERE promotion_id = p_promotion_id AND status = 'open';
    RETURN NEXT v_promo;
  END IF;
END;
$$;


ALTER FUNCTION public.end_promotion(p_promotion_id uuid) OWNER TO postgres;

--
-- Name: enqueue_notion_sync(uuid, uuid, jsonb); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.enqueue_notion_sync(p_lead_id uuid, p_artist_id uuid, p_payload jsonb) RETURNS uuid
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.enqueue_notion_sync(p_lead_id uuid, p_artist_id uuid, p_payload jsonb) OWNER TO postgres;

--
-- Name: fail_notion_sync_row(uuid, text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fail_notion_sync_row(p_id uuid, p_error text) RETURNS void
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.fail_notion_sync_row(p_id uuid, p_error text) OWNER TO postgres;

--
-- Name: returning_customers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.returning_customers (
    artist_id uuid NOT NULL,
    telefone text NOT NULL,
    nome text,
    source text DEFAULT 'imported'::text NOT NULL,
    confirmed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT returning_customers_source_check CHECK ((source = ANY (ARRAY['imported'::text, 'deposit_confirmed'::text])))
);


ALTER TABLE public.returning_customers OWNER TO postgres;

--
-- Name: import_returning_customer(uuid, text, text, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.import_returning_customer(p_artist_id uuid, p_telefone text, p_nome text DEFAULT NULL::text, p_confirmed_at timestamp with time zone DEFAULT NULL::timestamp with time zone) RETURNS SETOF public.returning_customers
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.import_returning_customer(p_artist_id uuid, p_telefone text, p_nome text, p_confirmed_at timestamp with time zone) OWNER TO postgres;

--
-- Name: is_returning_customer(uuid, text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.is_returning_customer(p_artist_id uuid, p_telefone text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.is_returning_customer(p_artist_id uuid, p_telefone text) OWNER TO postgres;

--
-- Name: lookup_price(text, text, uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.lookup_price(p_placement text, p_body_zone text, p_artist_id uuid) RETURNS TABLE(placement text, body_zone text, table_price integer, session_duration_min integer, buffer_min integer)
    LANGUAGE plpgsql STABLE
    AS $$
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
$$;


ALTER FUNCTION public.lookup_price(p_placement text, p_body_zone text, p_artist_id uuid) OWNER TO postgres;

--
-- Name: pause_promotion(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.pause_promotion(p_promotion_id uuid) RETURNS SETOF public.promotions
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_promo promotions;
BEGIN
  UPDATE promotions SET
    status = 'paused',
    paused_at = now(),
    updated_at = now()
  WHERE id = p_promotion_id AND status = 'active'
  RETURNING * INTO v_promo;
  IF v_promo.id IS NOT NULL THEN RETURN NEXT v_promo; END IF;
END;
$$;


ALTER FUNCTION public.pause_promotion(p_promotion_id uuid) OWNER TO postgres;

--
-- Name: request_deposit(uuid, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.request_deposit(p_lead_id uuid, p_amount integer) RETURNS SETOF public.leads
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.request_deposit(p_lead_id uuid, p_amount integer) OWNER TO postgres;

--
-- Name: reset_leads(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.reset_leads(p_artist_slug text DEFAULT NULL::text) RETURNS jsonb
    LANGUAGE plpgsql
    AS $_$
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
$_$;


ALTER FUNCTION public.reset_leads(p_artist_slug text) OWNER TO postgres;

--
-- Name: resolve_artist_from_session(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.resolve_artist_from_session(p_wa_session_slug text) RETURNS SETOF public.artists
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM artists
    WHERE wa_session_slug = p_wa_session_slug
      AND status IN ('stub', 'onboarding', 'live')
    LIMIT 1;
END;
$$;


ALTER FUNCTION public.resolve_artist_from_session(p_wa_session_slug text) OWNER TO postgres;

--
-- Name: resume_artist(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.resume_artist(p_artist_id uuid) RETURNS SETOF public.artists
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.resume_artist(p_artist_id uuid) OWNER TO postgres;

--
-- Name: chat_control; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.chat_control (
    chat_id text NOT NULL,
    artist_id uuid NOT NULL,
    owner text DEFAULT 'beatriz'::text NOT NULL,
    taken_at timestamp with time zone,
    returned_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT chat_control_owner_check CHECK ((owner = ANY (ARRAY['beatriz'::text, 'artist'::text])))
);


ALTER TABLE public.chat_control OWNER TO postgres;

--
-- Name: return_chat_control(text, uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.return_chat_control(p_chat_id text, p_artist_id uuid) RETURNS SETOF public.chat_control
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_row chat_control;
BEGIN
  UPDATE chat_control SET
    owner = 'beatriz',
    returned_at = now(),
    updated_at = now()
  WHERE chat_id = p_chat_id AND artist_id = p_artist_id
  RETURNING * INTO v_row;
  IF v_row.chat_id IS NOT NULL THEN RETURN NEXT v_row; END IF;
END;
$$;


ALTER FUNCTION public.return_chat_control(p_chat_id text, p_artist_id uuid) OWNER TO postgres;

--
-- Name: set_artist_context(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.set_artist_context(target_artist_id uuid) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
  PERFORM set_config('app.artist_id', target_artist_id::TEXT, true);
END;
$$;


ALTER FUNCTION public.set_artist_context(target_artist_id uuid) OWNER TO postgres;

--
-- Name: suspend_artist(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.suspend_artist(p_artist_id uuid) RETURNS SETOF public.artists
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
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
$$;


ALTER FUNCTION public.suspend_artist(p_artist_id uuid) OWNER TO postgres;

--
-- Name: take_chat_control(text, uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.take_chat_control(p_chat_id text, p_artist_id uuid) RETURNS SETOF public.chat_control
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_row chat_control;
BEGIN
  INSERT INTO chat_control (chat_id, artist_id, owner, taken_at, returned_at)
  VALUES (p_chat_id, p_artist_id, 'artist', now(), NULL)
  ON CONFLICT (chat_id) DO UPDATE SET
    owner = 'artist',
    taken_at = now(),
    returned_at = NULL,
    updated_at = now()
  RETURNING * INTO v_row;

  UPDATE leads SET updated_at = now()
  WHERE artist_id = p_artist_id
    AND telefone = split_part(p_chat_id, ':', 2);

  RETURN NEXT v_row;
END;
$$;


ALTER FUNCTION public.take_chat_control(p_chat_id text, p_artist_id uuid) OWNER TO postgres;

--
-- Name: unblock_slot(uuid); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.unblock_slot(p_block_id uuid) RETURNS SETOF public.calendar
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.unblock_slot(p_block_id uuid) OWNER TO postgres;

--
-- Name: validate_onboarding_token(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.validate_onboarding_token(p_token text) RETURNS SETOF public.artists
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    AS $$
BEGIN
  RETURN QUERY
    SELECT * FROM artists
    WHERE onboarding_token = p_token
      AND status = 'stub'
      AND created_at > now() - INTERVAL '24 hours';
END;
$$;


ALTER FUNCTION public.validate_onboarding_token(p_token text) OWNER TO postgres;

--
-- Name: promotion_quotes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.promotion_quotes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    promotion_id uuid NOT NULL,
    lead_id uuid NOT NULL,
    artist_id uuid NOT NULL,
    placement text NOT NULL,
    body_zone text NOT NULL,
    first_price integer NOT NULL,
    second_cap_price integer NOT NULL,
    quoted_at timestamp with time zone DEFAULT now() NOT NULL,
    valid_until timestamp with time zone NOT NULL,
    status text DEFAULT 'open'::text NOT NULL,
    secured_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT promotion_quotes_status_check CHECK ((status = ANY (ARRAY['open'::text, 'secured'::text, 'lapsed'::text, 'void'::text])))
);


ALTER TABLE public.promotion_quotes OWNER TO postgres;

--
-- Name: write_promotion_quote(uuid, uuid, text, text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.write_promotion_quote(p_lead_id uuid, p_promotion_id uuid, p_placement text, p_body_zone text) RETURNS SETOF public.promotion_quotes
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
DECLARE
  v_promo promotions;
  v_price promotion_prices;
  v_quote promotion_quotes;
BEGIN
  SELECT * INTO v_promo FROM promotions WHERE id = p_promotion_id;
  IF NOT FOUND THEN RETURN; END IF;

  -- Reuse an existing open (or secured) quote for this lead+promotion.
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
$$;


ALTER FUNCTION public.write_promotion_quote(p_lead_id uuid, p_promotion_id uuid, p_placement text, p_body_zone text) OWNER TO postgres;

--
-- Name: write_quote(uuid, integer, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.write_quote(p_lead_id uuid, p_table_price integer, p_negotiated_price integer DEFAULT NULL::integer) RETURNS SETOF public.leads
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.write_quote(p_lead_id uuid, p_table_price integer, p_negotiated_price integer) OWNER TO postgres;

--
-- Name: chat_memory; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.chat_memory (
    id integer NOT NULL,
    session_id character varying(255) NOT NULL,
    message jsonb NOT NULL
);


ALTER TABLE public.chat_memory OWNER TO postgres;

--
-- Name: chat_memory_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.chat_memory_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.chat_memory_id_seq OWNER TO postgres;

--
-- Name: chat_memory_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.chat_memory_id_seq OWNED BY public.chat_memory.id;


--
-- Name: events; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lead_id uuid NOT NULL,
    artist_id uuid NOT NULL,
    event_type text NOT NULL,
    payload jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.events OWNER TO postgres;

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: message_buffer; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.message_buffer (
    chat_id text NOT NULL,
    pending text,
    last_msg_at timestamp with time zone DEFAULT now() NOT NULL,
    last_typing_at timestamp with time zone,
    typing_state text
);


ALTER TABLE public.message_buffer OWNER TO postgres;

--
-- Name: TABLE message_buffer; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.message_buffer IS 'Buffers recent Telegram messages per chat for debounce — the SDR responds only after the user pauses typing.';


--
-- Name: COLUMN message_buffer.pending; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.message_buffer.pending IS 'Accumulated message text for the current in-progress burst';


--
-- Name: COLUMN message_buffer.last_msg_at; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.message_buffer.last_msg_at IS 'Timestamp of the most recent message in this chat';


--
-- Name: COLUMN message_buffer.last_typing_at; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.message_buffer.last_typing_at IS 'Timestamp of the most recent typing indicator from this chat — extended while the user is actively typing';


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: notes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.notes (
    id uuid NOT NULL,
    lead_id uuid NOT NULL,
    author_id bigint NOT NULL,
    body text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notes OWNER TO postgres;

--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO postgres;

--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.personal_access_tokens OWNER TO postgres;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.personal_access_tokens_id_seq OWNER TO postgres;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: pricing; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.pricing (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    artist_id uuid NOT NULL,
    placement text NOT NULL,
    body_zone text NOT NULL,
    table_price integer NOT NULL,
    session_duration_min integer NOT NULL,
    buffer_min integer DEFAULT 30,
    creeps integer DEFAULT 0,
    active boolean DEFAULT true,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.pricing OWNER TO postgres;

--
-- Name: promotion_prices; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.promotion_prices (
    promotion_id uuid NOT NULL,
    placement text NOT NULL,
    body_zone text NOT NULL,
    base_price integer NOT NULL,
    offer_price integer NOT NULL
);


ALTER TABLE public.promotion_prices OWNER TO postgres;

--
-- Name: promotion_types; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.promotion_types (
    key text NOT NULL,
    name text NOT NULL,
    description text,
    config_spec jsonb DEFAULT '{}'::jsonb NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.promotion_types OWNER TO postgres;

--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    role character varying(255) DEFAULT 'artist'::character varying NOT NULL,
    artist_id uuid,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: chat_memory id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chat_memory ALTER COLUMN id SET DEFAULT nextval('public.chat_memory_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: artists artists_onboarding_token_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.artists
    ADD CONSTRAINT artists_onboarding_token_key UNIQUE (onboarding_token);


--
-- Name: artists artists_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.artists
    ADD CONSTRAINT artists_pkey PRIMARY KEY (id);


--
-- Name: artists artists_wa_session_slug_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.artists
    ADD CONSTRAINT artists_wa_session_slug_key UNIQUE (wa_session_slug);


--
-- Name: calendar calendar_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.calendar
    ADD CONSTRAINT calendar_pkey PRIMARY KEY (id);


--
-- Name: chat_control chat_control_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chat_control
    ADD CONSTRAINT chat_control_pkey PRIMARY KEY (chat_id);


--
-- Name: chat_memory chat_memory_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chat_memory
    ADD CONSTRAINT chat_memory_pkey PRIMARY KEY (id);


--
-- Name: events events_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: leads leads_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leads
    ADD CONSTRAINT leads_pkey PRIMARY KEY (id);


--
-- Name: message_buffer message_buffer_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.message_buffer
    ADD CONSTRAINT message_buffer_pkey PRIMARY KEY (chat_id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: notes notes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_pkey PRIMARY KEY (id);


--
-- Name: notion_sync_outbox notion_sync_outbox_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notion_sync_outbox
    ADD CONSTRAINT notion_sync_outbox_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: pricing pricing_artist_id_placement_body_zone_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pricing
    ADD CONSTRAINT pricing_artist_id_placement_body_zone_key UNIQUE (artist_id, placement, body_zone);


--
-- Name: pricing pricing_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pricing
    ADD CONSTRAINT pricing_pkey PRIMARY KEY (id);


--
-- Name: promotion_prices promotion_prices_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_prices
    ADD CONSTRAINT promotion_prices_pkey PRIMARY KEY (promotion_id, placement, body_zone);


--
-- Name: promotion_quotes promotion_quotes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_quotes
    ADD CONSTRAINT promotion_quotes_pkey PRIMARY KEY (id);


--
-- Name: promotion_types promotion_types_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_types
    ADD CONSTRAINT promotion_types_pkey PRIMARY KEY (key);


--
-- Name: promotions promotions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotions
    ADD CONSTRAINT promotions_pkey PRIMARY KEY (id);


--
-- Name: returning_customers returning_customers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.returning_customers
    ADD CONSTRAINT returning_customers_pkey PRIMARY KEY (artist_id, telefone);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: idx_calendar_artist_range; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_calendar_artist_range ON public.calendar USING btree (artist_id, start_at, end_at);


--
-- Name: idx_events_lead; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_events_lead ON public.events USING btree (lead_id, created_at);


--
-- Name: idx_leads_artist_phone; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leads_artist_phone ON public.leads USING btree (artist_id, telefone);


--
-- Name: idx_leads_notion_sync; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leads_notion_sync ON public.leads USING btree (notion_sync_status) WHERE (notion_sync_status = 'pending'::text);


--
-- Name: idx_leads_pipeline; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leads_pipeline ON public.leads USING btree (artist_id, pipeline_status);


--
-- Name: idx_outbox_pending; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_outbox_pending ON public.notion_sync_outbox USING btree (next_attempt_at) WHERE (status = ANY (ARRAY['pending'::text, 'failed'::text]));


--
-- Name: idx_pricing_artist; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_pricing_artist ON public.pricing USING btree (artist_id, active);


--
-- Name: idx_promo_quotes_lead; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_promo_quotes_lead ON public.promotion_quotes USING btree (lead_id, status);


--
-- Name: idx_promo_quotes_promo; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_promo_quotes_promo ON public.promotion_quotes USING btree (promotion_id, status);


--
-- Name: idx_promotions_artist; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_promotions_artist ON public.promotions USING btree (artist_id, status);


--
-- Name: notes_lead_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX notes_lead_id_index ON public.notes USING btree (lead_id);


--
-- Name: one_active_promotion_per_artist; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX one_active_promotion_per_artist ON public.promotions USING btree (artist_id) WHERE (status = 'active'::text);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: users_role_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX users_role_index ON public.users USING btree (role);


--
-- Name: calendar calendar_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.calendar
    ADD CONSTRAINT calendar_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id);


--
-- Name: chat_control chat_control_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chat_control
    ADD CONSTRAINT chat_control_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES public.artists(id);


--
-- Name: events events_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.events
    ADD CONSTRAINT events_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id);


--
-- Name: notes notes_author_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_author_id_foreign FOREIGN KEY (author_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: notes notes_lead_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_lead_id_foreign FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE CASCADE;


--
-- Name: notion_sync_outbox notion_sync_outbox_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notion_sync_outbox
    ADD CONSTRAINT notion_sync_outbox_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES public.artists(id);


--
-- Name: notion_sync_outbox notion_sync_outbox_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notion_sync_outbox
    ADD CONSTRAINT notion_sync_outbox_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id);


--
-- Name: promotion_prices promotion_prices_promotion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_prices
    ADD CONSTRAINT promotion_prices_promotion_id_fkey FOREIGN KEY (promotion_id) REFERENCES public.promotions(id) ON DELETE CASCADE;


--
-- Name: promotion_quotes promotion_quotes_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_quotes
    ADD CONSTRAINT promotion_quotes_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES public.artists(id);


--
-- Name: promotion_quotes promotion_quotes_lead_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_quotes
    ADD CONSTRAINT promotion_quotes_lead_id_fkey FOREIGN KEY (lead_id) REFERENCES public.leads(id) ON DELETE CASCADE;


--
-- Name: promotion_quotes promotion_quotes_promotion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotion_quotes
    ADD CONSTRAINT promotion_quotes_promotion_id_fkey FOREIGN KEY (promotion_id) REFERENCES public.promotions(id) ON DELETE CASCADE;


--
-- Name: promotions promotions_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotions
    ADD CONSTRAINT promotions_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES public.artists(id);


--
-- Name: promotions promotions_type_key_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.promotions
    ADD CONSTRAINT promotions_type_key_fkey FOREIGN KEY (type_key) REFERENCES public.promotion_types(key);


--
-- Name: returning_customers returning_customers_artist_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.returning_customers
    ADD CONSTRAINT returning_customers_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES public.artists(id);


--
-- Name: users users_artist_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_artist_id_foreign FOREIGN KEY (artist_id) REFERENCES public.artists(id) ON DELETE SET NULL;


--
-- Name: chat_control; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.chat_control ENABLE ROW LEVEL SECURITY;

--
-- Name: chat_control chat_control_artist_isolation; Type: POLICY; Schema: public; Owner: postgres
--

CREATE POLICY chat_control_artist_isolation ON public.chat_control USING ((artist_id = (current_setting('app.artist_id'::text))::uuid)) WITH CHECK ((artist_id = (current_setting('app.artist_id'::text))::uuid));


--
-- Name: promotion_prices; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.promotion_prices ENABLE ROW LEVEL SECURITY;

--
-- Name: promotion_prices promotion_prices_artist_isolation; Type: POLICY; Schema: public; Owner: postgres
--

CREATE POLICY promotion_prices_artist_isolation ON public.promotion_prices USING ((promotion_id IN ( SELECT promotions.id
   FROM public.promotions
  WHERE (promotions.artist_id = (current_setting('app.artist_id'::text))::uuid))));


--
-- Name: promotion_quotes; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.promotion_quotes ENABLE ROW LEVEL SECURITY;

--
-- Name: promotion_quotes promotion_quotes_artist_isolation; Type: POLICY; Schema: public; Owner: postgres
--

CREATE POLICY promotion_quotes_artist_isolation ON public.promotion_quotes USING ((artist_id = (current_setting('app.artist_id'::text))::uuid)) WITH CHECK ((artist_id = (current_setting('app.artist_id'::text))::uuid));


--
-- Name: promotions; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.promotions ENABLE ROW LEVEL SECURITY;

--
-- Name: promotions promotions_artist_isolation; Type: POLICY; Schema: public; Owner: postgres
--

CREATE POLICY promotions_artist_isolation ON public.promotions USING ((artist_id = (current_setting('app.artist_id'::text))::uuid)) WITH CHECK ((artist_id = (current_setting('app.artist_id'::text))::uuid));


--
-- Name: returning_customers; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.returning_customers ENABLE ROW LEVEL SECURITY;

--
-- Name: returning_customers returning_customers_artist_isolation; Type: POLICY; Schema: public; Owner: postgres
--

CREATE POLICY returning_customers_artist_isolation ON public.returning_customers USING ((artist_id = (current_setting('app.artist_id'::text))::uuid)) WITH CHECK ((artist_id = (current_setting('app.artist_id'::text))::uuid));


--
-- PostgreSQL database dump complete
--

\unrestrict i8NA3cxPktJrdEksvcmypAqoFyv6Pw0vYBdhuYHbx4Es47Et1wd8BfJh5cXtzLl


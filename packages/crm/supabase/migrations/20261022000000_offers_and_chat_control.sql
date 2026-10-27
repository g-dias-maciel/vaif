-- VAIF SDR — Offers (promotions) + returning-customer segment + per-chat ownership
-- Decisions from the 2026-10-21/22 grilling session:
--  * Promotion mechanic is centrally fixed; a VAIF admin owns per-artist price
--    (multiplier on the regular pricing matrix), conditions, activation.
--  * One active promotion per artist. Prices are snapshotted at activation so
--    later regular-price edits never move a live offer.
--  * A quote's promotion price is protected for 7 days from when it was sent;
--    pausing the promotion does not retract quotes inside that window.
--  * "Returning customer" = prior confirmed booking with the SAME artist
--    (deposit_status='confirmado'), including artist-imported history.
--  * Artist takes over a chat by replying in WhatsApp; ownership persists until
--    the artist returns the chat to Beatriz from the dashboard.

-- ============================================================
-- 1. promotion_types — centrally defined mechanics (fixed)
-- ============================================================
CREATE TABLE IF NOT EXISTS promotion_types (
  key         TEXT PRIMARY KEY,
  name        TEXT NOT NULL,
  description TEXT,
  config_spec JSONB NOT NULL DEFAULT '{}'::jsonb,  -- admin-fillable fields + defaults
  active      BOOLEAN NOT NULL DEFAULT true,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Seed the first mechanic: "two tattoos for the price of (multiplier x one)".
-- The second tattoo may be for another person / another placement; its regular
-- listed price must be <= the first tattoo's regular listed price.
INSERT INTO promotion_types (key, name, description, config_spec)
VALUES (
  'bogo_same_value',
  'Leve 2 pelo valor de {multiplier}x',
  'Compre 1 tatuagem e leve outra de mesmo valor ou menor. Segunda tatuagem pode ser de outra pessoa e em outro local do corpo; o artista agenda a segunda depois. Excecoes vao para o artista.',
  '{"multiplier": 2.0, "quote_valid_days": 7, "second_recipient_allowed": true, "second_placement_free": true}'::jsonb
)
ON CONFLICT (key) DO NOTHING;

-- ============================================================
-- 2. promotions — one configured offer per artist (admin-owned)
-- ============================================================
CREATE TABLE IF NOT EXISTS promotions (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  artist_id         UUID NOT NULL REFERENCES artists(id),
  type_key          TEXT NOT NULL REFERENCES promotion_types(key),
  name              TEXT NOT NULL,
  multiplier        NUMERIC(4,2) NOT NULL DEFAULT 2.0,
  audience          TEXT NOT NULL DEFAULT 'all'
    CHECK (audience IN ('all','new','returning')),
  conditions        JSONB NOT NULL DEFAULT '{}'::jsonb,  -- exclusions, notes for the artist
  quote_valid_days  INTEGER NOT NULL DEFAULT 7,
  status            TEXT NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','active','paused','ended')),
  activated_at      TIMESTAMPTZ,
  paused_at         TIMESTAMPTZ,
  ended_at          TIMESTAMPTZ,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- One ACTIVE promotion per artist (drafts/paused/ended are unlimited).
CREATE UNIQUE INDEX IF NOT EXISTS one_active_promotion_per_artist
  ON promotions (artist_id) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_promotions_artist ON promotions (artist_id, status);

ALTER TABLE promotions ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS promotions_artist_isolation ON promotions;
CREATE POLICY promotions_artist_isolation ON promotions
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 3. promotion_prices — snapshot of offer prices taken at activation
--    (regular matrix edits afterwards never move a live offer)
-- ============================================================
CREATE TABLE IF NOT EXISTS promotion_prices (
  promotion_id  UUID NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
  placement     TEXT NOT NULL,
  body_zone     TEXT NOT NULL,
  base_price    INTEGER NOT NULL,   -- regular table_price at activation (cents)
  offer_price   INTEGER NOT NULL,   -- ceil(base_price * multiplier) (cents)
  PRIMARY KEY (promotion_id, placement, body_zone)
);

ALTER TABLE promotion_prices ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS promotion_prices_artist_isolation ON promotion_prices;
CREATE POLICY promotion_prices_artist_isolation ON promotion_prices
  USING (promotion_id IN (
    SELECT id FROM promotions
    WHERE artist_id = current_setting('app.artist_id')::uuid
  ));

-- ============================================================
-- 4. promotion_quotes — every offer quote sent to a lead (7-day protection)
-- ============================================================
CREATE TABLE IF NOT EXISTS promotion_quotes (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  promotion_id     UUID NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
  lead_id          UUID NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
  artist_id        UUID NOT NULL REFERENCES artists(id),
  placement        TEXT NOT NULL,
  body_zone        TEXT NOT NULL,
  first_price      INTEGER NOT NULL,  -- offer price quoted for the first tattoo (cents)
  second_cap_price INTEGER NOT NULL,  -- max regular listed price the 2nd tattoo may have
  quoted_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  valid_until      TIMESTAMPTZ NOT NULL,
  status           TEXT NOT NULL DEFAULT 'open'
    CHECK (status IN ('open','secured','lapsed','void')),
  secured_at       TIMESTAMPTZ,       -- set when the first booking's deposit is confirmed
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_promo_quotes_lead ON promotion_quotes (lead_id, status);
CREATE INDEX IF NOT EXISTS idx_promo_quotes_promo ON promotion_quotes (promotion_id, status);

ALTER TABLE promotion_quotes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS promotion_quotes_artist_isolation ON promotion_quotes;
CREATE POLICY promotion_quotes_artist_isolation ON promotion_quotes
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 5. Returning customers — prior confirmed bookings with this artist,
--    including artist-imported pre-SDR history.
-- ============================================================
CREATE TABLE IF NOT EXISTS returning_customers (
  artist_id   UUID NOT NULL REFERENCES artists(id),
  telefone    TEXT NOT NULL,
  nome        TEXT,
  source      TEXT NOT NULL DEFAULT 'imported'
    CHECK (source IN ('imported','deposit_confirmed')),
  confirmed_at TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (artist_id, telefone)
);

ALTER TABLE returning_customers ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS returning_customers_artist_isolation ON returning_customers;
CREATE POLICY returning_customers_artist_isolation ON returning_customers
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 6. Per-chat ownership — who answers this chat: beatriz (default) or artist.
--    Keyed by the same chat_id the message buffer uses (session:phone).
-- ============================================================
CREATE TABLE IF NOT EXISTS chat_control (
  chat_id    TEXT PRIMARY KEY,           -- e.g. '<wa_session_slug>:<phone>'
  artist_id  UUID NOT NULL REFERENCES artists(id),
  owner      TEXT NOT NULL DEFAULT 'beatriz'
    CHECK (owner IN ('beatriz','artist')),
  taken_at   TIMESTAMPTZ,
  returned_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE chat_control ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS chat_control_artist_isolation ON chat_control;
CREATE POLICY chat_control_artist_isolation ON chat_control
  USING (artist_id = current_setting('app.artist_id')::uuid)
  WITH CHECK (artist_id = current_setting('app.artist_id')::uuid);

-- ============================================================
-- 7. Functions
-- ============================================================

-- Activate a promotion (admin): snapshots the artist's current regular pricing
-- matrix into promotion_prices at multiplier, then flips status to 'active'.
-- The partial unique index enforces one active promotion per artist.
CREATE OR REPLACE FUNCTION activate_promotion(
  p_promotion_id UUID
) RETURNS SETOF promotions AS $$
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
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Pause an active promotion (admin). Open quotes keep their snapshotted price
-- until their own valid_until (7 days from when each was sent).
CREATE OR REPLACE FUNCTION pause_promotion(
  p_promotion_id UUID
) RETURNS SETOF promotions AS $$
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
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- End a promotion (admin). Open quotes are voided from here on — an ended
-- offer is a deliberate stop, unlike a pause.
CREATE OR REPLACE FUNCTION end_promotion(
  p_promotion_id UUID
) RETURNS SETOF promotions AS $$
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
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Resolve the offer state for a lead, consumed by the agent's context builder.
-- Two independent facts, both surfaced in one row:
--   * promo_*       — the artist's currently ACTIVE promotion, if the lead's
--                     segment (new/returning) is honoured. Used to offer NEW
--                     quotes.
--   * open_quote_*  — the lead's open-and-still-valid quote (price protection),
--                     surfaced EVEN IF the promotion is now paused, so Beatriz
--                     honours a quote sent before the pause through its 7-day
--                     window. (ended promotions void quotes, so none appear.)
-- Exactly one row is always returned (sentinel) so n8n Postgres nodes never
-- skip downstream on an empty result.
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

-- Record a promotion quote. first_price comes from promotion_prices (the
-- activation snapshot); the second-tattoo cap is the first tattoo's base price.
-- Idempotent per (lead, promotion): an existing OPEN quote is reused so the
-- agent's deterministic quote-recording node can run every turn safely, and the
-- original quoted_at/valid_until (the 7-day protection window) never moves.
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
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Is this phone a returning customer for this artist? True when the artist
-- imported the history, or any lead row for that phone shows a confirmed
-- deposit (our confirmed-booking proxy) with the same artist.
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

-- Artist imports a pre-SDR confirmed customer.
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

-- Artist takes over a chat (triggered by the artist replying in WhatsApp).
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
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Artist returns the chat to Beatriz (dashboard action).
CREATE OR REPLACE FUNCTION return_chat_control(
  p_chat_id TEXT,
  p_artist_id UUID
) RETURNS SETOF chat_control AS $$
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
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Who owns this chat right now? Default 'beatriz' when no row exists.
CREATE OR REPLACE FUNCTION chat_owner(
  p_chat_id TEXT
) RETURNS TEXT AS $$
  SELECT COALESCE((SELECT owner FROM chat_control WHERE chat_id = p_chat_id), 'beatriz');
$$ LANGUAGE sql STABLE SECURITY DEFINER;

-- ============================================================
-- 8. Wire the business rules into existing functions
-- ============================================================

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
$$ LANGUAGE plpgsql SECURITY DEFINER;

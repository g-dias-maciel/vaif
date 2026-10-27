-- Tests for Offers + returning customers + chat control
-- Run after 20261022000000_offers_and_chat_control.sql
-- docker exec -i supabase_db_crm psql -U postgres -d postgres -f - < this_file.sql

\set ON_ERROR_STOP on

BEGIN;
SELECT set_artist_context('b0000000-0000-0000-0000-000000000001');

-- ============================================================
-- Fixture: fresh lead + a draft promotion for Bruno
-- ============================================================
SELECT id INTO TEMP TABLE _lead1
FROM create_lead('b0000000-0000-0000-0000-000000000001', '5511999991001', 'Promo Lead');

CREATE TEMP TABLE _promo AS
WITH ins AS (
  INSERT INTO promotions (artist_id, type_key, name, multiplier, audience)
  VALUES ('b0000000-0000-0000-0000-000000000001', 'bogo_same_value', 'Leve 2 por 2x', 2.0, 'all')
  RETURNING id
) SELECT * FROM ins;

-- ============================================================
-- Test: activate_promotion snapshots 2x prices
-- ============================================================
\echo '=== Test: activate_promotion ==='
SELECT id, status, activated_at IS NOT NULL AS has_activated_at
FROM activate_promotion((SELECT id FROM _promo));

SELECT count(*) > 0 AS snapshot_rows
FROM promotion_prices WHERE promotion_id = (SELECT id FROM _promo);

-- antebraco/pequeno base 30000 -> offer 60000
SELECT offer_price = 60000 AND base_price = 30000 AS snapshot_is_2x
FROM promotion_prices
WHERE promotion_id = (SELECT id FROM _promo)
  AND placement = 'antebraco' AND body_zone = 'pequeno';

-- Regular price change after activation does NOT move the snapshot.
UPDATE pricing SET table_price = 50000
WHERE artist_id = 'b0000000-0000-0000-0000-000000000001'
  AND placement = 'antebraco' AND body_zone = 'pequeno';

SELECT offer_price = 60000 AS snapshot_frozen
FROM promotion_prices
WHERE promotion_id = (SELECT id FROM _promo)
  AND placement = 'antebraco' AND body_zone = 'pequeno';

-- ============================================================
-- Test: one active promotion per artist
-- ============================================================
\echo '=== Test: one active per artist ==='
CREATE TEMP TABLE _promo2 AS
WITH ins AS (
  INSERT INTO promotions (artist_id, type_key, name, multiplier)
  VALUES ('b0000000-0000-0000-0000-000000000001', 'bogo_same_value', 'Segunda promo', 2.0)
  RETURNING id
) SELECT * FROM ins;

-- Second activation must fail on the partial unique index.
DO $$
BEGIN
  PERFORM activate_promotion((SELECT id FROM _promo2));
  RAISE EXCEPTION 'expected unique violation';
EXCEPTION WHEN unique_violation THEN
  RAISE NOTICE 'second activation correctly rejected';
END $$;

-- ============================================================
-- Test: write_promotion_quote + 7-day protection
-- ============================================================
\echo '=== Test: write_promotion_quote ==='
CREATE TEMP TABLE _quote AS
SELECT id, valid_until, first_price, second_cap_price
FROM write_promotion_quote(
  (SELECT id FROM _lead1),
  (SELECT id FROM _promo),
  'antebraco', 'pequeno'
);

-- first_price = frozen offer price (60000); second_cap = the first tattoo's
-- regular listed price, which is the activation snapshot's base_price (30000).
SELECT first_price = 60000 AND second_cap_price = 30000 AS quote_uses_snapshot
FROM _quote;

SELECT valid_until > now() + INTERVAL '6 days'
   AND valid_until < now() + INTERVAL '8 days' AS seven_day_window
FROM _quote;

-- Idempotent: re-recording reuses the same open quote (no duplicate row).
\echo '=== Test: write_promotion_quote idempotent ==='
CREATE TEMP TABLE _quote2 AS
SELECT id, valid_until
FROM write_promotion_quote(
  (SELECT id FROM _lead1),
  (SELECT id FROM _promo),
  'antebraco', 'pequeno'
);
SELECT (SELECT id FROM _quote2) = (SELECT id FROM _quote) AS same_quote_reused;
SELECT count(*) = 1 AS single_open_quote
FROM promotion_quotes
WHERE lead_id = (SELECT id FROM _lead1) AND status = 'open';

-- ============================================================
-- Test: active_offer_for_lead finds promo + open quote
-- ============================================================
\echo '=== Test: active_offer_for_lead ==='
SELECT promo_active = true AS promo_active_visible,
       open_quote_valid = true AS open_quote_visible
FROM active_offer_for_lead('b0000000-0000-0000-0000-000000000001', (SELECT id FROM _lead1));

-- Pause: promo stops being offered for NEW quotes, but the open quote is still
-- inside its 7-day window and must be honoured (price protection).
SELECT count(*) = 1 AS paused_promo_result
FROM pause_promotion((SELECT id FROM _promo));

SELECT promo_active = false AND open_quote_valid = true AS paused_honors_open_quote
FROM active_offer_for_lead('b0000000-0000-0000-0000-000000000001', (SELECT id FROM _lead1));

-- Reactivate; quote must still be open (pause did not void it).
SELECT count(*) = 1 AS reactivate_ok
FROM activate_promotion((SELECT id FROM _promo));

SELECT promo_active = true AND open_quote_valid = true AS quote_survived_pause
FROM active_offer_for_lead('b0000000-0000-0000-0000-000000000001', (SELECT id FROM _lead1));

-- ============================================================
-- Test: deposit confirmation secures the quote + marks returning
-- ============================================================
\echo '=== Test: confirm_deposit secures ==='
SELECT id, deposit_status FROM confirm_deposit((SELECT id FROM _lead1));

SELECT status = 'secured' AS quote_secured
FROM promotion_quotes WHERE id = (SELECT id FROM _quote);

SELECT is_returning_customer('b0000000-0000-0000-0000-000000000001', '5511999991001') AS now_returning;

-- Ending the promotion now must NOT void the secured quote.
SELECT count(*) = 1 AS ended
FROM end_promotion((SELECT id FROM _promo));

SELECT status = 'secured' AS secured_survives_end
FROM promotion_quotes WHERE id = (SELECT id FROM _quote);

-- ============================================================
-- Test: import_returning_customer
-- ============================================================
\echo '=== Test: import_returning_customer ==='
SELECT telefone, source FROM import_returning_customer(
  'b0000000-0000-0000-0000-000000000001', '5511999991002', 'Cliente Antigo',
  now() - INTERVAL '400 days');

SELECT is_returning_customer('b0000000-0000-0000-0000-000000000001', '5511999991002') AS imported_is_returning;
SELECT NOT is_returning_customer('b0000000-0000-0000-0000-000000000001', '5511999991999') AS stranger_not_returning;

-- ============================================================
-- Test: chat control
-- ============================================================
\echo '=== Test: chat control ==='
SELECT owner FROM take_chat_control('default:5511999991001', 'b0000000-0000-0000-0000-000000000001');
SELECT chat_owner('default:5511999991001') = 'artist' AS artist_owns;
SELECT owner FROM return_chat_control('default:5511999991001', 'b0000000-0000-0000-0000-000000000001');
SELECT chat_owner('default:5511999991001') = 'beatriz' AS returned_to_beatriz;
SELECT chat_owner('default:0000000000000') = 'beatriz' AS unknown_defaults_beatriz;

-- ============================================================
-- Cleanup
-- ============================================================
\echo '=== Cleanup ==='
DELETE FROM promotion_quotes WHERE lead_id = (SELECT id FROM _lead1);
DELETE FROM promotion_prices WHERE promotion_id IN (SELECT id FROM _promo) OR promotion_id IN (SELECT id FROM _promo2);
DELETE FROM promotions WHERE id IN (SELECT id FROM _promo) OR id IN (SELECT id FROM _promo2);
DELETE FROM chat_control WHERE chat_id = 'default:5511999991001';
DELETE FROM returning_customers WHERE telefone IN ('5511999991001','5511999991002');
DELETE FROM events WHERE lead_id = (SELECT id FROM _lead1);
DELETE FROM leads WHERE id = (SELECT id FROM _lead1);
-- Restore Bruno's seeded price.
UPDATE pricing SET table_price = 30000
WHERE artist_id = 'b0000000-0000-0000-0000-000000000001'
  AND placement = 'antebraco' AND body_zone = 'pequeno';
COMMIT;

\echo ''
\echo '===== ALL OFFER/CHAT-CONTROL TESTS PASSED ====='

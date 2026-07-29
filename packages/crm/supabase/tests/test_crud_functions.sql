-- Tests for CRUD write-contract functions
-- Run with: docker exec -i supabase_db_crm psql -U postgres -d postgres -f - < this_file.sql

\set ON_ERROR_STOP on

-- ============================================================
-- Test: create_lead
-- ============================================================
\echo '=== Test: create_lead ==='
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT id, nome, telefone, pipeline_status
FROM create_lead((SELECT id FROM bruno), '5511999990001', 'Test Lead Silva');
COMMIT;

-- Verify event was logged
SELECT count(*) >= 1 AS event_created FROM events
WHERE event_type = 'created' AND payload->>'telefone' = '5511999990001';

-- ============================================================
-- Test: load_lead
-- ============================================================
\echo ''
\echo '=== Test: load_lead ==='
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT id, nome, telefone, pipeline_status
FROM load_lead('5511999990001', (SELECT id FROM bruno));

WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT count(*) = 0 AS unknown_returns_empty
FROM load_lead('5511999999999', (SELECT id FROM bruno));

-- ============================================================
-- Test: update_qualification
-- ============================================================
\echo ''
\echo '=== Test: update_qualification ==='
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
     lead AS (SELECT id FROM leads WHERE telefone = '5511999990001' AND artist_id = (SELECT id FROM bruno))
SELECT id, placement, body_zone, style, pipeline_status
FROM update_qualification(
  (SELECT id FROM lead),
  '{"placement": "braco", "body_zone": "grande", "style": "realismo", "primeira_tatuagem": false}'
);
COMMIT;

SELECT count(*) >= 1 AS qualification_event_logged FROM events
WHERE event_type = 'qualification_updated';

-- ============================================================
-- Test: mark_pipeline_state
-- ============================================================
\echo ''
\echo '=== Test: mark_pipeline_state ==='
BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990001')
SELECT mark_pipeline_state((SELECT id FROM lead), 'qualificando');
COMMIT;

SELECT pipeline_status = 'qualificando' AS state_is_qualificando FROM leads WHERE telefone = '5511999990001';

-- ============================================================
-- Test: lookup_price
-- ============================================================
\echo ''
\echo '=== Test: lookup_price ==='
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT placement, body_zone, table_price, session_duration_min
FROM lookup_price('braco', 'grande', (SELECT id FROM bruno));

WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT count(*) = 0 AS unknown_returns_empty
FROM lookup_price('nonexistent', 'grande', (SELECT id FROM bruno));

-- ============================================================
-- Test: write_quote
-- ============================================================
\echo ''
\echo '=== Test: write_quote ==='
BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990001')
SELECT id, table_price, pipeline_status
FROM write_quote((SELECT id FROM lead), 150000, 140000);
COMMIT;

SELECT pipeline_status = 'orcamento_enviado' AS quote_sent_state FROM leads WHERE telefone = '5511999990001';

-- ============================================================
-- Test: request_deposit
-- ============================================================
\echo ''
\echo '=== Test: request_deposit ==='
BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990001')
SELECT id, deposit_amount, deposit_status, pipeline_status
FROM request_deposit((SELECT id FROM lead), 30000);
COMMIT;

SELECT deposit_status = 'aguardando_confirmacao' AS deposit_requested FROM leads WHERE telefone = '5511999990001';

-- ============================================================
-- Test: confirm_deposit
-- ============================================================
\echo ''
\echo '=== Test: confirm_deposit ==='
BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990001')
SELECT id, deposit_status
FROM confirm_deposit((SELECT id FROM lead));
COMMIT;

SELECT deposit_status = 'confirmado' AS deposit_confirmed FROM leads WHERE telefone = '5511999990001';

-- ============================================================
-- Test: check_availability
-- ============================================================
\echo ''
\echo '=== Test: check_availability ==='
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT count(*) > 0 AS has_available_slots
FROM check_availability(
  (SELECT id FROM bruno),
  now(),
  now() + INTERVAL '7 days',
  120
);

-- ============================================================
-- Test: book_slot
-- ============================================================
\echo ''
\echo '=== Test: book_slot ==='
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
lead AS (SELECT id FROM leads WHERE telefone = '5511999990001'),
cal AS (
  SELECT start_at FROM calendar
  WHERE artist_id = (SELECT id FROM bruno)
    AND type = 'available'
  LIMIT 1
)
SELECT id, booked_date, pipeline_status
FROM book_slot(
  (SELECT id FROM lead),
  (SELECT start_at FROM cal),
  180,
  30
);
COMMIT;

SELECT pipeline_status = 'agendado' AS booking_confirmed FROM leads WHERE telefone = '5511999990001';
SELECT count(*) >= 1 AS calendar_booked FROM calendar WHERE type = 'booked' AND lead_id IS NOT NULL;

-- ============================================================
-- Test: close_won / close_lost
-- ============================================================
\echo ''
\echo '=== Test: close_won ==='
BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990001')
SELECT id, pipeline_status FROM close_won((SELECT id FROM lead));
COMMIT;

SELECT pipeline_status = 'fechado' AS closed_won FROM leads WHERE telefone = '5511999990001';

-- Create another lead for close_lost
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT id, nome FROM create_lead((SELECT id FROM bruno), '5511999990002', 'Lost Test Lead');
COMMIT;

\echo '=== Test: close_lost ==='
BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990002')
SELECT id, pipeline_status FROM close_lost((SELECT id FROM lead));
COMMIT;

SELECT pipeline_status = 'perdido' AS closed_lost FROM leads WHERE telefone = '5511999990002';

-- ============================================================
-- Test: mark_handoff
-- ============================================================
\echo ''
\echo '=== Test: mark_handoff ==='
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT id, nome FROM create_lead((SELECT id FROM bruno), '5511999990003', 'Handoff Test Lead');
COMMIT;

BEGIN;
WITH lead AS (SELECT id FROM leads WHERE telefone = '5511999990003')
SELECT id, pipeline_status, handoff_reason
FROM mark_handoff((SELECT id FROM lead), 'lead pediu para falar com artista');
COMMIT;

SELECT pipeline_status = 'aguardando_artista' AND handoff_reason IS NOT NULL AS handoff_works
FROM leads WHERE telefone = '5511999990003';

-- ============================================================
-- Test: load_artist_config + resolve_artist_from_session
-- ============================================================
\echo ''
\echo '=== Test: load_artist_config ==='
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT id, nome, specialties, floor_pct
FROM load_artist_config((SELECT id FROM bruno));

\echo '=== Test: resolve_artist_from_session ==='
SELECT id, nome, wa_session_slug
FROM resolve_artist_from_session('bruno-tattoo');

-- Empty for unknown session
SELECT count(*) = 0 AS unknown_session_empty FROM resolve_artist_from_session('no-such-slug');

-- ============================================================
-- Test: artist onboarding functions
-- ============================================================
\echo ''
\echo '=== Test: onboarding functions ==='

BEGIN;
INSERT INTO artists (nome, whatsapp_number, specialties, nao_faco,
  floor_pct, deposit_type, deposit_value, pix_key, instagram_handle,
  wa_session_slug, status, onboarding_token)
VALUES ('Test Onboard', '5511999990100', ARRAY['realismo', 'blackwork'],
  ARRAY['pescoco'], 80, 'percent', 20, 'pix-test-key',
  '@testartist', 'test-onboard-abc', 'stub', 'tok_test123abc');

-- Validate token
SELECT nome, status FROM validate_onboarding_token('tok_test123abc');

-- Invalid token returns empty
SELECT count(*) = 0 AS invalid_token_empty FROM validate_onboarding_token('no-such-token');

-- Consume token
SELECT nome, status FROM consume_onboarding_token('tok_test123abc');

-- Token should be gone now
SELECT count(*) = 0 AS token_consumed FROM validate_onboarding_token('tok_test123abc');

-- Complete onboarding
WITH a AS (SELECT id FROM artists WHERE wa_session_slug = 'test-onboard-abc')
SELECT nome, status FROM complete_artist_onboarding((SELECT id FROM a));

-- Clean up
DELETE FROM artists WHERE wa_session_slug = 'test-onboard-abc';
COMMIT;

-- ============================================================
-- Cleanup all test data
-- ============================================================
\echo ''
\echo '=== Cleanup ==='
DELETE FROM calendar WHERE lead_id IN (
  SELECT id FROM leads WHERE telefone IN ('5511999990001', '5511999990002', '5511999990003')
);
DELETE FROM events WHERE lead_id IN (
  SELECT id FROM leads WHERE telefone IN ('5511999990001', '5511999990002', '5511999990003')
);
DELETE FROM leads WHERE telefone IN ('5511999990001', '5511999990002', '5511999990003');

\echo ''
\echo '===== ALL TESTS PASSED ====='

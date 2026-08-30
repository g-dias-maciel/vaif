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
-- Test: check_availability (derived 60-min slots from working_hours)
-- ============================================================
\echo ''
\echo '=== Test: check_availability (derived) ==='

-- No pre-seeded 'available' rows exist anymore (seed removed). Availability
-- is derived from the artist's working_hours, so it must never be empty for
-- an artist with working_hours set, over any 60-day window.
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT count(*) > 0 AS derived_slots_never_empty
FROM check_availability(
  (SELECT id FROM bruno),
  date_trunc('day', now())::timestamptz,
  now() + INTERVAL '60 days',
  120
);

-- Legacy 'available' calendar rows are ignored entirely.
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
legacy AS (
  INSERT INTO calendar (artist_id, start_at, end_at, type)
  SELECT (SELECT id FROM bruno), now() + INTERVAL '2 days', now() + INTERVAL '2 days 5 hours', 'available'
  RETURNING start_at
)
SELECT count(*) = 0 AS legacy_available_ignored
FROM check_availability(
  (SELECT id FROM bruno),
  now(),
  now() + INTERVAL '3 days',
  60
) ca
JOIN legacy l ON l.start_at = ca.start_at;

-- Slots are 60-minute aligned (starts on the hour) and sized to the requested duration.
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT count(*) = 0 AS all_slots_hour_aligned
FROM check_availability(
  (SELECT id FROM bruno),
  now(),
  now() + INTERVAL '30 days',
  120
)
WHERE EXTRACT(MINUTE FROM start_at) <> 0
   OR EXTRACT(SECOND FROM start_at) <> 0;

-- Artist without working_hours has no derived slots.
BEGIN;
INSERT INTO artists (nome, wa_session_slug, status) VALUES ('No Hours Test', 'no-hours-test', 'live');
WITH a AS (SELECT id FROM artists WHERE wa_session_slug = 'no-hours-test')
SELECT count(*) = 0 AS no_working_hours_empty
FROM check_availability((SELECT id FROM a), now(), now() + INTERVAL '60 days', 60);
DELETE FROM artists WHERE wa_session_slug = 'no-hours-test';
COMMIT;

-- Blocked periods are excluded from the result.
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
s AS (
  SELECT start_at
  FROM check_availability((SELECT id FROM bruno), now(), now() + INTERVAL '30 days', 60)
  ORDER BY start_at
  LIMIT 1
)
INSERT INTO calendar (artist_id, start_at, end_at, type)
SELECT (SELECT id FROM bruno), start_at, start_at + INTERVAL '90 minutes', 'blocked'
FROM s;
COMMIT;

WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
blocked AS (
  SELECT start_at, start_at + INTERVAL '90 minutes' AS end_at
  FROM calendar WHERE type = 'blocked' LIMIT 1
)
SELECT count(*) = 0 AS blocked_slots_excluded
FROM check_availability(
  (SELECT id FROM bruno),
  now(),
  now() + INTERVAL '30 days',
  60
) ca
JOIN blocked b ON b.start_at < ca.end_at AND b.end_at > ca.start_at;

-- ============================================================
-- Test: book_slot (books a derived slot)
-- ============================================================
\echo ''
\echo '=== Test: book_slot ==='
BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
lead AS (SELECT id FROM leads WHERE telefone = '5511999990001'),
slot AS (
  SELECT start_at
  FROM check_availability((SELECT id FROM bruno), now(), now() + INTERVAL '30 days', 180)
  ORDER BY start_at
  LIMIT 1
)
SELECT id, booked_date, pipeline_status
FROM book_slot(
  (SELECT id FROM lead),
  (SELECT start_at FROM slot),
  180,
  30
);
COMMIT;

SELECT pipeline_status = 'agendado' AS booking_confirmed FROM leads WHERE telefone = '5511999990001';
SELECT count(*) >= 1 AS calendar_booked FROM calendar WHERE type = 'booked' AND lead_id IS NOT NULL;

-- The booked slot is excluded from subsequent availability.
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT count(*) = 0 AS booked_slots_excluded
FROM check_availability(
  (SELECT id FROM bruno),
  now(),
  now() + INTERVAL '30 days',
  180
) ca
JOIN calendar c ON c.type = 'booked' AND c.lead_id IS NOT NULL
  AND c.start_at = ca.start_at;

-- ============================================================
-- Test: book_slot overlap rejection
-- ============================================================
\echo ''
\echo '=== Test: book_slot overlap rejection ==='

-- Two fresh leads compete for the same window.
BEGIN;
SELECT id FROM create_lead((SELECT id FROM artists WHERE nome = 'Bruno'), '5511999990004', 'Overlap Lead A');
SELECT id FROM create_lead((SELECT id FROM artists WHERE nome = 'Bruno'), '5511999990005', 'Overlap Lead B');
COMMIT;

-- Pin one concrete 60-minute slot so both attempts target the same window.
DROP TABLE IF EXISTS _slot_under_test;
CREATE TEMP TABLE _slot_under_test AS
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT start_at
FROM check_availability((SELECT id FROM bruno), now(), now() + INTERVAL '30 days', 60)
ORDER BY start_at
LIMIT 1;

-- Lead A books the slot -> succeeds.
BEGIN;
WITH lead_a AS (SELECT id FROM leads WHERE telefone = '5511999990004')
SELECT count(*) = 1 AS first_booking_succeeds
FROM book_slot((SELECT id FROM lead_a), (SELECT start_at FROM _slot_under_test), 60, 30);
COMMIT;

-- Lead B attempts the same window -> rejected (book_slot returns nothing).
BEGIN;
WITH lead_b AS (SELECT id FROM leads WHERE telefone = '5511999990005')
SELECT count(*) = 0 AS overlapping_booking_rejected
FROM book_slot((SELECT id FROM lead_b), (SELECT start_at FROM _slot_under_test), 60, 30);
COMMIT;

-- Rejected lead is unchanged: still 'novo', no calendar row.
SELECT pipeline_status = 'novo' AS rejected_lead_unchanged FROM leads WHERE telefone = '5511999990005';
SELECT count(*) = 0 AS rejected_lead_has_no_row
FROM calendar WHERE lead_id = (SELECT id FROM leads WHERE telefone = '5511999990005');

-- A blocked period also rejects a booking over it.
DROP TABLE IF EXISTS _block_under_test;
CREATE TEMP TABLE _block_under_test AS
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT start_at
FROM check_availability((SELECT id FROM bruno), now(), now() + INTERVAL '30 days', 60)
WHERE start_at >= (SELECT start_at FROM _slot_under_test) + INTERVAL '1 hour'
ORDER BY start_at
LIMIT 1;

BEGIN;
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno'),
b AS (SELECT start_at FROM _block_under_test)
SELECT count(*) = 1 AS block_created
FROM block_slot((SELECT id FROM bruno), (SELECT start_at FROM b), (SELECT start_at FROM b) + INTERVAL '2 hours');
COMMIT;

BEGIN;
WITH lead_b AS (SELECT id FROM leads WHERE telefone = '5511999990005'),
b AS (SELECT start_at FROM _block_under_test)
SELECT count(*) = 0 AS blocked_period_rejects_booking
FROM book_slot((SELECT id FROM lead_b), (SELECT start_at FROM b), 60, 30);
COMMIT;

-- ============================================================
-- Test: block_slot + unblock_slot
-- ============================================================
\echo ''
\echo '=== Test: block_slot ==='

DROP TABLE IF EXISTS _test_block;
CREATE TEMP TABLE _test_block AS
WITH bruno AS (SELECT id FROM artists WHERE nome = 'Bruno')
SELECT id, artist_id, type, start_at, end_at
FROM block_slot(
  (SELECT id FROM bruno),
  now() + INTERVAL '10 days',
  now() + INTERVAL '10 days 4 hours'
);

SELECT count(*) = 1 AS block_slot_created FROM _test_block;
SELECT count(*) = 1 AS blocked_row_in_calendar
FROM calendar WHERE id = (SELECT id FROM _test_block) AND type = 'blocked';

\echo '=== Test: unblock_slot ==='
BEGIN;
SELECT count(*) = 1 AS unblock_removes_row
FROM unblock_slot((SELECT id FROM _test_block));
COMMIT;

SELECT count(*) = 0 AS blocked_row_removed
FROM calendar WHERE id = (SELECT id FROM _test_block);

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
  SELECT id FROM leads WHERE telefone IN ('5511999990001', '5511999990002', '5511999990003', '5511999990004', '5511999990005')
);
DELETE FROM calendar WHERE artist_id = (SELECT id FROM artists WHERE nome = 'Bruno');
DELETE FROM events WHERE lead_id IN (
  SELECT id FROM leads WHERE telefone IN ('5511999990001', '5511999990002', '5511999990003', '5511999990004', '5511999990005')
);
DELETE FROM leads WHERE telefone IN ('5511999990001', '5511999990002', '5511999990003', '5511999990004', '5511999990005');

\echo ''
\echo '===== ALL TESTS PASSED ====='

-- VAIF SDR Closer — seed data for local testing
-- Requires 001-schema.sql to be applied first

-- Since RLS requires app.artist_id to be set, we temporarily bypass it for seeding:
-- Run this with: supabase db execute --file packages/crm/migrations/002-seed.sql
-- Or paste into the Supabase SQL Editor with the service_role key (bypasses RLS).

-- ============================================================
-- Sample artist: Bruno (tattoo artist)
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
  '{
    "seg": ["09:00-12:00", "14:00-18:00"],
    "ter": ["09:00-12:00", "14:00-18:00"],
    "qua": ["09:00-12:00", "14:00-18:00"],
    "qui": ["09:00-12:00", "14:00-18:00"],
    "sex": ["09:00-12:00", "14:00-18:00"],
    "sab": ["09:00-13:00"]
  }'::jsonb,
  NULL,
  'America/Sao_Paulo',
  'bruno-tattoo',
  'live',
  '5511999990001'
);

-- ============================================================
-- Sample pricing for Bruno (6 common placements × zones)
-- ============================================================
INSERT INTO pricing (artist_id, placement, body_zone, table_price, session_duration_min, buffer_min)
VALUES
  -- antebraço
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'pequeno',    30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'medio',      60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'grande',     90000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'antebraco',   'fechamento', 120000, 240, 30),

  -- braço externo
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'pequeno',    30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'medio',      60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'grande',     90000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'braco_externo', 'fechamento', 150000, 300, 30),

  -- costas
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'pequeno',    35000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'medio',      70000, 150,  30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'grande',     120000, 240, 30),
  ('b0000000-0000-0000-0000-000000000001', 'costas',       'fechamento', 200000, 360, 30),

  -- panturrilha
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'pequeno',    25000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'medio',      50000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'grande',     80000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'panturrilha',  'fechamento', 110000, 240, 30),

  -- peito
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'pequeno',    30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'medio',      60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'grande',     100000, 180, 30),
  ('b0000000-0000-0000-0000-000000000001', 'peito',        'fechamento', 160000, 300, 30),

  -- perna
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'pequeno',    30000,  90,  30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'medio',      60000, 120,  30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'grande',     100000, 180,  30),
  ('b0000000-0000-0000-0000-000000000001', 'perna',        'fechamento', 140000, 300, 30);

-- ============================================================
-- Calendar slots: NO LONGER SEEDED.
-- Availability is derived on the fly from artists.working_hours (see
-- check_availability), interpreted in the artist's timezone. Pre-seeding
-- 'available' rows is removed so stale slots can't reappear. The calendar
-- table only ever holds 'booked'/'blocked' rows from here on.
-- ============================================================

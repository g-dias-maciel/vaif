-- =============================================================================
-- Per-artist blocked numbers
--
-- The SDR agent must not answer these numbers: no reply, no Lead, no Project.
-- Stored as digits only (DDI + DDD + number); the workflow normalizes the
-- WhatsApp JID (including LID) to the same form before comparing.
-- =============================================================================

alter table artist_settings
  add column if not exists blocked_numbers text[] not null default '{}';

comment on column artist_settings.blocked_numbers is
  'Digits-only phone numbers the SDR agent silently ignores.';

-- =============================================================================
-- Agent sales mode
--
-- Per-artist switch between the full SDR flow and triage-only:
--   * true  (default) — Beatriz qualifies, quotes, negotiates, books and asks
--                       for the deposit (current behaviour).
--   * false           — Beatriz only triages the lead and hands off to the
--                       artist before talking about price or scheduling.
--
-- The column is returned automatically by `resolve_artist` because that RPC
-- serialises the whole `artist_settings` row (`to_jsonb(s)`), so the agent
-- picks it up without a function change. Existing artists keep the full flow.
-- =============================================================================

alter table artist_settings
  add column if not exists full_selling boolean not null default true;

comment on column artist_settings.full_selling is
  'When false, the SDR agent only triages and hands off before price/scheduling.';

-- =============================================================================
-- Internal team Google Calendar
--
-- Reuses the existing VAIF service account: each internal user shares their
-- calendar with the service account e-mail, and team events are mirrored into
-- the responsible user's calendar.
--
-- Mirrors app/database/migrations/2026_11_09_000005_add_team_google_calendar.php
-- for the SQLite test schema.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Internal user's connected calendar
-- -----------------------------------------------------------------------------
alter table public.users
  add column if not exists google_calendar_id text;
alter table public.users
  add column if not exists google_calendar_summary text;
alter table public.users
  add column if not exists google_calendar_timezone text;
alter table public.users
  add column if not exists google_connected_at timestamptz;

-- -----------------------------------------------------------------------------
-- 2. Per-event Google mirror state
-- -----------------------------------------------------------------------------
alter table public.team_events
  add column if not exists google_event_id text;
alter table public.team_events
  add column if not exists google_calendar_id text;
alter table public.team_events
  add column if not exists google_sync_pending boolean not null default false;
alter table public.team_events
  add column if not exists google_synced_at timestamptz;
alter table public.team_events
  add column if not exists google_sync_error text;
alter table public.team_events
  add column if not exists deleted_at timestamptz;

create index if not exists team_events_google_pending
  on public.team_events (google_sync_pending)
  where google_sync_pending;
create index if not exists team_events_google_calendar
  on public.team_events (google_calendar_id);
create index if not exists team_events_deleted
  on public.team_events (deleted_at);

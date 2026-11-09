-- =============================================================================
-- Internal team calendar — sales, strategy and other meetings
--
-- Prospect sales calls are not duplicated here: they are read from
-- prospects.booked_call_at when the calendar renders. This table stores the
-- meetings the team creates manually.
--
-- Mirrors app/database/migrations/2026_11_09_000004_create_team_events.php
-- for the SQLite test schema.
-- =============================================================================

create table if not exists public.team_events (
  id           uuid primary key default gen_random_uuid(),
  title        text not null,
  type         text not null default 'other'
               check (type in ('sales','strategy','other')),
  starts_at    timestamptz not null,
  ends_at      timestamptz not null,
  location     text,
  meeting_url  text,
  notes        text,
  owner_id     bigint references public.users(id) on delete set null,
  prospect_id  uuid references public.prospects(id) on delete set null,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now(),
  check (ends_at > starts_at)
);

create index if not exists team_events_range on public.team_events (starts_at, ends_at);
create index if not exists team_events_type on public.team_events (type);
create index if not exists team_events_owner on public.team_events (owner_id);
create index if not exists team_events_prospect on public.team_events (prospect_id);

drop trigger if exists trg_team_events_touch on public.team_events;
create trigger trg_team_events_touch before update on public.team_events
  for each row execute function touch_updated_at();

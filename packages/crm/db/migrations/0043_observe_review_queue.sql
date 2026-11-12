-- =============================================================================
-- Observe mode — Phase 2: review queue for money and calendar writes
--
-- The observer writes discovery fields directly. Anything that changes money
-- or the calendar (booking, reschedule, quote, deposit, stage) is either
-- applied when both sides are explicit, or parked here for one-click review.
-- =============================================================================

create table if not exists observed_writes (
  id               uuid primary key default gen_random_uuid(),
  artist_id        uuid not null references artists(id) on delete cascade,
  lead_id          uuid references leads(id) on delete cascade,
  project_id       uuid references projects(id) on delete cascade,
  chat_id          text,
  observed_turn_id uuid references observed_turns(id) on delete set null,
  kind             text not null check (kind in (
                     'booking', 'reschedule', 'deposit_request', 'deposit_confirm',
                     'quote', 'stage', 'note'
                   )),
  status           text not null default 'pending' check (status in (
                     'pending', 'approved', 'rejected', 'superseded', 'auto_applied'
                   )),
  dedupe_key       text not null,
  confidence       numeric(3, 2),
  payload          jsonb not null default '{}'::jsonb,
  evidence         jsonb not null default '{}'::jsonb,
  decided_by       bigint references users(id) on delete set null,
  decided_at       timestamptz,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now()
);

create unique index if not exists observed_writes_dedupe
  on observed_writes (artist_id, dedupe_key);

create index if not exists observed_writes_pending
  on observed_writes (artist_id, status, created_at desc);

create index if not exists observed_writes_lead
  on observed_writes (lead_id, status, created_at desc);

comment on table observed_writes is
  'Observe-mode proposals that touch money or the calendar; approved by the artist or staff.';

grant select, insert, update, delete on observed_writes to crm_app;
grant select on observed_writes to analytics_ro;

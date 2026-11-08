-- =============================================================================
-- Copilot reports — Phase 1
--
-- Deterministic facts + an LLM-written narrative for two audiences:
--   ops         = queue, WAHA/webhook drift, handoffs, stuck drafts, drops
--   calibration = draft-vs-artist quality, gates, top failure rules
--
-- Reports are staff-only, read-only and never execute actions. Findings carry
-- suggested actions as text; approval/execution is a later phase.
-- =============================================================================

create table if not exists copilot_reports (
  id            uuid primary key default gen_random_uuid(),
  kind          text not null check (kind in ('ops', 'calibration')),
  period_key    text not null,
  artist_id     uuid references artists(id) on delete cascade,
  period_start  timestamptz,
  period_end    timestamptz,
  status        text not null default 'queued',
  facts         jsonb not null default '{}'::jsonb,
  narrative     text,
  findings      jsonb not null default '[]'::jsonb,
  model         text,
  generated_at  timestamptz,
  delivered_at  timestamptz,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create unique index if not exists copilot_reports_kind_period
  on copilot_reports (kind, period_key);

create index if not exists copilot_reports_kind_time
  on copilot_reports (kind, generated_at desc);

comment on table copilot_reports is
  'Staff-only ops/calibration reports: deterministic facts + narrative, no autonomous actions.';

grant select, insert, update, delete on copilot_reports to crm_app;
grant select on copilot_reports to analytics_ro;

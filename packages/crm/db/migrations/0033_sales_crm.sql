-- =============================================================================
-- Internal sales CRM — Artist prospects from the calculator funnel
--
-- This is VAIF's own sales pipeline, not the Artist-facing CRM. A `prospect` is
-- an Artist prospect (CONTEXT.md), never a tattoo-customer Lead. The Artist
-- app never reads these tables; access is staff/sales only at the route layer.
--
-- Mirrors app/database/migrations/2026_11_09_000000_create_sales_crm_tables.php
-- for the SQLite test schema. Production applies this SQL directly.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Campaigns — attribution and spend
-- -----------------------------------------------------------------------------
create table if not exists public.campaigns (
  id              uuid primary key default gen_random_uuid(),
  name            text not null,
  channel         text not null default 'meta'
                  check (channel in ('meta','google','organic','referral','event','other')),
  status          text not null default 'draft'
                  check (status in ('draft','active','paused','ended')),
  starts_on       date,
  ends_on         date,
  budget_cents    bigint check (budget_cents is null or budget_cents >= 0),
  spend_cents     bigint not null default 0 check (spend_cents >= 0),
  utm_campaign    text,
  creative_note   text,
  notes           text,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

create index if not exists campaigns_status on public.campaigns (status);
create index if not exists campaigns_channel on public.campaigns (channel);
create index if not exists campaigns_utm on public.campaigns (utm_campaign);

-- -----------------------------------------------------------------------------
-- 2. Internal pipeline stages
-- -----------------------------------------------------------------------------
create table if not exists public.prospect_stages (
  key        text primary key,
  label_pt   text not null,
  sort_order integer not null unique,
  is_open    boolean not null default true,
  is_won     boolean not null default false,
  is_lost    boolean not null default false
);

insert into public.prospect_stages (key, label_pt, sort_order, is_open, is_won, is_lost)
values
  ('novo',          'Novo',              10, true,  false, false),
  ('qualificado',   'Qualificado',       20, true,  false, false),
  ('call_agendada', 'Call agendada',     30, true,  false, false),
  ('call_realizada','Call realizada',    40, true,  false, false),
  ('proposta',      'Proposta',          50, true,  false, false),
  ('ganho',         'Ganho',             60, false, true,  false),
  ('perdido',       'Perdido',           70, false, false, true),
  ('nutricao',      'Nutrição',          80, true,  false, false)
on conflict (key) do update set
  label_pt   = excluded.label_pt,
  sort_order = excluded.sort_order,
  is_open    = excluded.is_open,
  is_won     = excluded.is_won,
  is_lost    = excluded.is_lost;

-- -----------------------------------------------------------------------------
-- 3. Prospects — one row per Artist prospect
-- -----------------------------------------------------------------------------
create table if not exists public.prospects (
  id                       uuid primary key default gen_random_uuid(),
  name                     text not null,
  whatsapp_e164            text not null,
  whatsapp_raw             text,
  instagram                text,
  email                    text,
  studio                   text,
  style                    text,
  city                     text,
  source                   text not null default 'calculadora',
  status                   text not null default 'backlog'
                           check (status in ('active','backlog','archived')),
  stage_key                text references public.prospect_stages(key) on delete set null,
  revenue_monthly_cents    bigint check (revenue_monthly_cents is null or revenue_monthly_cents >= 0),
  ticket_cents             bigint check (ticket_cents is null or ticket_cents >= 0),
  sessions_monthly         integer check (sessions_monthly is null or sessions_monthly >= 0),
  admin_hours_day          numeric(5,2) check (admin_hours_day is null or admin_hours_day >= 0),
  hidden_loss_cents        bigint,
  potential_revenue_cents  bigint,
  revenue_band             text not null default 'unknown'
                           check (revenue_band in ('full_service','retainer','entry','nurture','unknown')),
  score                    smallint not null default 0 check (score between 0 and 100),
  owner_id                 bigint,
  campaign_id              uuid references public.campaigns(id) on delete set null,
  booked_call_at           timestamptz,
  last_contact_at          timestamptz,
  next_action_at           timestamptz,
  next_action_note         text,
  lost_reason              text,
  notes                    text,
  artist_id                uuid references public.artists(id) on delete set null,
  handoff_at               timestamptz,
  legacy_id                integer,
  legacy_notion_id         text,
  legacy_status            text,
  import_flags             jsonb,
  raw_payload              jsonb,
  created_at               timestamptz not null default now(),
  updated_at               timestamptz not null default now(),
  constraint prospects_active_has_stage check (status <> 'active' or stage_key is not null)
);

create unique index if not exists prospects_whatsapp_e164_key on public.prospects (whatsapp_e164);
create unique index if not exists prospects_legacy_id_key on public.prospects (legacy_id) where legacy_id is not null;
create index if not exists prospects_status on public.prospects (status);
create index if not exists prospects_stage on public.prospects (stage_key);
create index if not exists prospects_owner on public.prospects (owner_id);
create index if not exists prospects_campaign on public.prospects (campaign_id);
create index if not exists prospects_band on public.prospects (revenue_band);
create index if not exists prospects_next_action on public.prospects (next_action_at);
create index if not exists prospects_created on public.prospects (created_at desc);
create index if not exists prospects_booked_call on public.prospects (booked_call_at);

-- -----------------------------------------------------------------------------
-- 4. Activities — calls, WhatsApp, notes, tasks, stage changes, handoff
-- -----------------------------------------------------------------------------
create table if not exists public.prospect_activities (
  id            bigserial primary key,
  prospect_id   uuid not null references public.prospects(id) on delete cascade,
  user_id       bigint,
  type          text not null
                check (type in ('call','whatsapp','note','task','stage_change','handoff','import','contract')),
  title         text,
  body          text,
  due_at        timestamptz,
  completed_at  timestamptz,
  metadata      jsonb,
  created_at    timestamptz not null default now()
);

create index if not exists prospect_activities_prospect on public.prospect_activities (prospect_id, created_at desc);
create index if not exists prospect_activities_due on public.prospect_activities (due_at);
create index if not exists prospect_activities_type on public.prospect_activities (type);

-- -----------------------------------------------------------------------------
-- 5. Contracts — source of truth for plan, MRR and invoice hand-off
-- -----------------------------------------------------------------------------
create table if not exists public.prospect_contracts (
  id                    uuid primary key default gen_random_uuid(),
  prospect_id           uuid not null references public.prospects(id) on delete cascade,
  artist_id             uuid references public.artists(id) on delete set null,
  plan                  text not null
                        check (plan in ('mensal','semestral','twelve_months','full_service','members_area')),
  monthly_value_cents   bigint not null check (monthly_value_cents >= 0),
  total_value_cents     bigint not null check (total_value_cents >= 0),
  start_date            date not null,
  end_date              date,
  status                text not null default 'draft'
                        check (status in ('draft','active','ended','churned')),
  signed_at             timestamptz,
  billing_day           smallint check (billing_day between 1 and 31),
  next_invoice_at       date,
  invoice_status        text check (invoice_status in ('pending','paid','overdue','cancelled')),
  external_ref          text,
  notes                 text,
  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now()
);

create index if not exists prospect_contracts_prospect on public.prospect_contracts (prospect_id);
create index if not exists prospect_contracts_status on public.prospect_contracts (status);
create index if not exists prospect_contracts_invoice on public.prospect_contracts (next_invoice_at);
create index if not exists prospect_contracts_artist on public.prospect_contracts (artist_id);

-- -----------------------------------------------------------------------------
-- 6. Onboarding tasks — the D0–D7 checklist created on handoff
-- -----------------------------------------------------------------------------
create table if not exists public.onboarding_tasks (
  id            uuid primary key default gen_random_uuid(),
  artist_id     uuid not null references public.artists(id) on delete cascade,
  key           text not null,
  title         text not null,
  due_at        timestamptz,
  completed_at  timestamptz,
  owner_id      bigint,
  notes         text,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),
  unique (artist_id, key)
);

create index if not exists onboarding_tasks_artist on public.onboarding_tasks (artist_id);
create index if not exists onboarding_tasks_due on public.onboarding_tasks (due_at);
create index if not exists onboarding_tasks_completed on public.onboarding_tasks (completed_at);

-- -----------------------------------------------------------------------------
-- 7. updated_at triggers (same function as the v2 schema)
-- -----------------------------------------------------------------------------
drop trigger if exists trg_campaigns_touch on public.campaigns;
create trigger trg_campaigns_touch before update on public.campaigns
  for each row execute function touch_updated_at();

drop trigger if exists trg_prospects_touch on public.prospects;
create trigger trg_prospects_touch before update on public.prospects
  for each row execute function touch_updated_at();

drop trigger if exists trg_prospect_contracts_touch on public.prospect_contracts;
create trigger trg_prospect_contracts_touch before update on public.prospect_contracts
  for each row execute function touch_updated_at();

drop trigger if exists trg_onboarding_tasks_touch on public.onboarding_tasks;
create trigger trg_onboarding_tasks_touch before update on public.onboarding_tasks
  for each row execute function touch_updated_at();

-- -----------------------------------------------------------------------------
-- 8. Grants — crm_app writes, analytics_ro reads
-- -----------------------------------------------------------------------------
grant select, insert, update, delete on public.campaigns          to crm_app;
grant select, insert, update, delete on public.prospect_stages    to crm_app;
grant select, insert, update, delete on public.prospects          to crm_app;
grant select, insert, update, delete on public.prospect_activities to crm_app;
grant select, insert, update, delete on public.prospect_contracts to crm_app;
grant select, insert, update, delete on public.onboarding_tasks   to crm_app;
grant usage, select on sequence public.prospect_activities_id_seq to crm_app;

grant select on public.campaigns           to analytics_ro;
grant select on public.prospect_stages     to analytics_ro;
grant select on public.prospects           to analytics_ro;
grant select on public.prospect_activities to analytics_ro;
grant select on public.prospect_contracts  to analytics_ro;
grant select on public.onboarding_tasks    to analytics_ro;

comment on table public.prospects is 'VAIF internal sales pipeline — Artist prospects, never tattoo-customer Leads.';
comment on column public.prospects.revenue_band is 'full_service >= R$75k; retainer >= R$15k; entry >= R$4k; nurture > 0; unknown otherwise.';
comment on column public.prospects.legacy_id is 'Original id in the LP MySQL leads table, for idempotent import.';

-- =============================================================================
-- VAIF CRM — schema v2 (target: PostgreSQL 17, self-hosted Coolify)
--
-- Implements docs/design/crm-data-model-v2.md (ADR-0002).
-- This file is DDL only: tables, constraints, indexes, partitions, triggers.
-- The RPC surface is in 0002_functions_v2.sql; roles/grants in 0003_roles_v2.sql.
--
-- Applied by packages/crm/db/rebuild-v2.sh on a database with an empty
-- `public` schema. It is NOT idempotent against a v1 schema: run it on a fresh
-- database (the cutover drops `public` first). Every statement is plain DDL so
-- a failed run is visible instead of silently skipped by IF NOT EXISTS.
--
-- Conventions (design §9):
--   * identifiers English snake_case, status VALUES Portuguese (agent vocabulary)
--   * money in _cents (integer), never floats
--   * timestamps in _at (timestamptz)
--   * phones in phone_e164, raw input kept in phone_raw
-- =============================================================================

-- Required for the calendar exclusion constraint (absent in v1 production).
create extension if not exists btree_gist;


-- =============================================================================
-- 1. Tenants and configuration (design §5.1)
-- =============================================================================

create table artists (
  id               uuid primary key default gen_random_uuid(),  -- UUIDv7 from the app
  name             text not null,
  status           text not null default 'stub'
                   check (status in ('stub','onboarding','live','suspended','offboarded')),
  timezone         text not null default 'America/Sao_Paulo',
  wa_session_slug  text not null unique,       -- WAHA session; infrastructure config
  onboarding_token text unique,                -- consumed when onboarding completes
  onboarded_at     timestamptz,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now()
);

create index artists_status on artists (status);


create table artist_settings (
  artist_id         uuid primary key references artists(id) on delete cascade,
  display_name      text,
  specialties       text[] not null default '{}',
  excluded_styles   text[] not null default '{}',      -- replaces nao_faco
  floor_pct         numeric(5,2) check (floor_pct between 0 and 100),
  deposit_type      text not null default 'percent'
                    check (deposit_type in ('percent','fixed')),
  deposit_value     integer check (deposit_value >= 0),
  pix_key           text,
  instagram_handle  text,
  whatsapp_number   text,
  telegram_group_id text,                               -- agent notifications
  working_hours     jsonb not null default '{}'::jsonb,
  ai_active_hours   jsonb not null default '{}'::jsonb,
  currency          text not null default 'BRL' check (char_length(currency) = 3),
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  check (jsonb_typeof(working_hours) = 'object'),
  check (jsonb_typeof(ai_active_hours) = 'object')
);


-- =============================================================================
-- 2. Leads and channels (design §5.2)
-- =============================================================================

create table leads (
  id              uuid primary key default gen_random_uuid(),
  artist_id       uuid not null references artists(id) on delete cascade,
  name            text,
  phone_e164      text not null,
  phone_raw       text,                            -- as the Lead typed it
  source          text,
  first_seen_at   timestamptz not null default now(),
  last_message_at timestamptz,
  archived_at     timestamptz,
  anonymized_at   timestamptz,                     -- LGPD erasure marker
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  constraint leads_artist_phone_key unique (artist_id, phone_e164),
  constraint leads_artist_id_key    unique (artist_id, id)   -- target for composite FKs
);

create index leads_artist_recent
  on leads (artist_id, last_message_at desc nulls last)
  where archived_at is null;


create table lead_channels (
  artist_id   uuid not null,
  lead_id     uuid not null,
  channel     text not null check (channel in ('whatsapp','instagram','telegram','web')),
  external_id text not null,                       -- WAHA chat id, IG thread, ...
  created_at  timestamptz not null default now(),
  primary key (artist_id, channel, external_id),
  foreign key (artist_id, lead_id) references leads (artist_id, id) on delete cascade
);

create index lead_channels_lead on lead_channels (artist_id, lead_id);


-- Replaces the global message_buffer / chat_control keys: a chat belongs to a
-- tenant, so the primary key is (artist_id, chat_id).
create table chat_sessions (
  artist_id        uuid not null references artists(id) on delete cascade,
  chat_id          text not null,
  lead_id          uuid not null,
  owner            text not null default 'agent' check (owner in ('agent','artist')),
  taken_at         timestamptz,
  returned_at      timestamptz,
  last_inbound_at  timestamptz,
  last_outbound_at timestamptz,
  typing_state     text,
  updated_at       timestamptz not null default now(),
  created_at       timestamptz not null default now(),
  primary key (artist_id, chat_id),
  foreign key (artist_id, lead_id) references leads (artist_id, id) on delete cascade
);

create index chat_sessions_lead on chat_sessions (artist_id, lead_id);


-- =============================================================================
-- 3. Pipeline stages and Projects (design §5.3)
-- =============================================================================

create table pipeline_stages (
  key        text primary key,
  label_pt   text not null,
  sort_order integer not null,
  is_open    boolean not null default true,
  is_won     boolean not null default false,
  is_lost    boolean not null default false,
  check (not (is_won and is_lost))
);

insert into pipeline_stages (key, label_pt, sort_order, is_open, is_won, is_lost) values
  ('novo',                'Novo',                10, true,  false, false),
  ('qualificando',        'Qualificando',        20, true,  false, false),
  ('orcamento_enviado',   'Orçamento enviado',   30, true,  false, false),
  ('aguardando_deposito', 'Aguardando sinal',    40, true,  false, false),
  ('agendado',            'Agendado',            50, true,  true,  false),
  ('aguardando_artista',  'Aguardando artista',  60, true,  false, false),
  ('fechado',             'Fechado',             70, false, true,  false),
  ('perdido',             'Perdido',             80, false, false, true),
  ('bloqueado',           'Bloqueado',           90, true,  false, false);


create table projects (
  id                uuid primary key default gen_random_uuid(),
  artist_id         uuid not null,
  lead_id           uuid not null,
  stage_key         text not null default 'novo' references pipeline_stages(key),
  placement         text,
  body_zone         text,
  style             text,
  first_tattoo      boolean,
  meaning           text,
  tattoo_type       text check (tattoo_type in ('new','cover_up')),
  reference_pics    text[] not null default '{}',
  handoff_reason    text,
  accepted_quote_id uuid,                          -- FK added after quotes exists
  closed_at         timestamptz,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  foreign key (artist_id, lead_id) references leads (artist_id, id) on delete cascade,
  constraint projects_artist_id_key unique (artist_id, id)
);

create index projects_board on projects (artist_id, stage_key, updated_at desc);
create index projects_lead  on projects (lead_id, created_at desc);
create index projects_open
  on projects (artist_id, updated_at desc)
  where closed_at is null;


-- =============================================================================
-- 4. Versioned price book (design §5.4)
-- =============================================================================

create table price_book (
  id                   uuid primary key default gen_random_uuid(),
  artist_id            uuid not null references artists(id) on delete cascade,
  placement            text not null,
  body_zone            text not null,
  base_price_cents     integer not null check (base_price_cents >= 0),
  session_duration_min integer not null check (session_duration_min > 0),
  buffer_min           integer not null default 30 check (buffer_min >= 0),
  effective_from       timestamptz not null default now(),
  effective_to         timestamptz,
  created_at           timestamptz not null default now(),
  check (effective_to is null or effective_to > effective_from)
);

-- Exactly one current row per matrix cell.
create unique index price_book_current
  on price_book (artist_id, placement, body_zone)
  where effective_to is null;

create index price_book_lookup
  on price_book (artist_id, placement, body_zone, effective_from desc);


-- =============================================================================
-- 5. Promotions (design §5.7) — created before quotes, which reference them
-- =============================================================================

create table promotion_types (
  key         text primary key,
  name        text not null,
  description text,
  config_spec jsonb not null default '{}'::jsonb,
  active      boolean not null default true,
  created_at  timestamptz not null default now()
);

insert into promotion_types (key, name, description, config_spec) values (
  'bogo_same_value',
  'Leve 2 pelo valor de {multiplier}x',
  'Compre 1 tatuagem e leve outra de mesmo valor ou menor. Segunda tatuagem pode ser de outra pessoa e em outro local do corpo; o artista agenda a segunda depois. Excecoes vao para o artista.',
  '{"multiplier": 2.0, "quote_valid_days": 7, "second_recipient_allowed": true, "second_placement_free": true}'::jsonb
);


create table promotions (
  id               uuid primary key default gen_random_uuid(),
  artist_id        uuid not null references artists(id) on delete cascade,
  type_key         text not null references promotion_types(key),
  name             text not null,
  multiplier       numeric(4,2) not null default 2.0,
  audience         text not null default 'all'
                   check (audience in ('all','new','returning')),
  conditions       jsonb not null default '{}'::jsonb,
  quote_valid_days integer not null default 7 check (quote_valid_days > 0),
  status           text not null default 'draft'
                   check (status in ('draft','active','paused','ended')),
  activated_at     timestamptz,
  paused_at        timestamptz,
  ended_at         timestamptz,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now(),
  constraint promotions_artist_id_key unique (artist_id, id)  -- target for quotes FK
);

-- One ACTIVE promotion per artist (drafts/paused/ended are unlimited).
create unique index one_active_promotion_per_artist
  on promotions (artist_id) where status = 'active';
create index promotions_artist on promotions (artist_id, status);


create table promotion_prices (
  promotion_id      uuid not null references promotions(id) on delete cascade,
  placement         text not null,
  body_zone         text not null,
  base_price_cents  integer not null check (base_price_cents >= 0),
  offer_price_cents integer not null check (offer_price_cents >= 0),
  primary key (promotion_id, placement, body_zone)
);


-- =============================================================================
-- 6. Quotes (design §5.4) — replaces promotion_quotes and lead price columns
-- =============================================================================

create table quotes (
  id                  uuid primary key default gen_random_uuid(),
  artist_id           uuid not null,
  project_id          uuid not null,
  kind                text not null default 'standard'
                      check (kind in ('standard','promotion')),
  promotion_id        uuid,
  base_price_cents    integer not null check (base_price_cents >= 0),
  offered_price_cents integer not null check (offered_price_cents >= 0),
  discount_percent    numeric(5,2),
  second_cap_cents    integer,                     -- "Leve 2" second tattoo cap
  valid_until         timestamptz,
  status              text not null default 'open'
                      check (status in ('open','accepted','declined','lapsed','void')),
  created_by          text not null default 'agent'
                      check (created_by in ('agent','artist','staff')),
  created_at          timestamptz not null default now(),
  accepted_at         timestamptz,
  foreign key (artist_id, project_id) references projects (artist_id, id) on delete cascade,
  foreign key (artist_id, promotion_id) references promotions (artist_id, id),
  check (kind = 'standard' or promotion_id is not null)
);

create unique index quotes_one_open_per_project
  on quotes (project_id) where status = 'open';
create index quotes_artist_status on quotes (artist_id, status);
create index quotes_project_history
  on quotes (artist_id, project_id, created_at desc);

-- Project <-> accepted quote is a cycle: defer the check to commit time.
alter table projects
  add constraint projects_accepted_quote_fkey
  foreign key (accepted_quote_id) references quotes (id)
  deferrable initially deferred;


-- =============================================================================
-- 7. Payments (design §5.4) — manual confirmation in v2
-- =============================================================================

create table payments (
  id            uuid primary key default gen_random_uuid(),
  artist_id     uuid not null,
  project_id    uuid not null,
  kind          text not null default 'deposit'
                check (kind in ('deposit','balance','refund')),
  amount_cents  integer not null check (amount_cents > 0),
  currency      text not null default 'BRL' check (char_length(currency) = 3),
  status        text not null default 'requested'
                check (status in ('requested','confirmed','failed','refunded')),
  method        text,                              -- pix, cash, card, ... (future Pix seam)
  external_ref  text,                              -- future Pix charge id
  requested_at  timestamptz not null default now(),
  confirmed_at  timestamptz,
  notes         text,
  foreign key (artist_id, project_id) references projects (artist_id, id) on delete cascade
);

create unique index payments_one_open_deposit
  on payments (project_id)
  where kind = 'deposit' and status in ('requested','confirmed');
create index payments_project_history
  on payments (artist_id, project_id, requested_at desc);


-- =============================================================================
-- 8. Calendar: bookings and blocks in one table (design §5.5)
-- =============================================================================

create table calendar_entries (
  id         uuid primary key default gen_random_uuid(),
  artist_id  uuid not null references artists(id) on delete cascade,
  kind       text not null check (kind in ('booking','block')),
  project_id uuid,
  lead_id    uuid,
  start_at   timestamptz not null,
  end_at     timestamptz not null,
  active     boolean not null default true,        -- cancelled bookings stay for audit
  reason     text,
  created_by text not null default 'agent'
             check (created_by in ('agent','artist','staff')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  check (end_at > start_at),
  check (kind = 'block' or (project_id is not null and lead_id is not null)),
  foreign key (artist_id, project_id) references projects (artist_id, id) on delete cascade,
  foreign key (artist_id, lead_id)    references leads (artist_id, id) on delete cascade
);

-- Database-enforced no double-booking. Overlap raises SQLSTATE 23P01.
alter table calendar_entries
  add constraint calendar_no_overlap
  exclude using gist (
    artist_id with =,
    tstzrange(start_at, end_at, '[)') with &&
  ) where (active);

create index calendar_artist_range
  on calendar_entries (artist_id, start_at)
  where active;
create index calendar_project
  on calendar_entries (artist_id, project_id)
  where active and project_id is not null;
create index calendar_lead
  on calendar_entries (artist_id, lead_id)
  where active and lead_id is not null;


-- =============================================================================
-- 9. Events: append-only, monthly partitions (design §5.6)
-- =============================================================================

-- Sequence default instead of IDENTITY: identical behaviour across partition
-- attach/detach and portable to PG 15/16.
create sequence events_id_seq;

create table events (
  id         bigint not null default nextval('events_id_seq'),
  artist_id  uuid not null references artists(id) on delete cascade,
  lead_id    uuid,
  project_id uuid,
  actor      text not null check (actor in ('agent','artist','staff','system')),
  type       text not null,
  payload    jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  primary key (id, created_at)                     -- partition key must be in the PK
) partition by range (created_at);

create index events_artist_time on events (artist_id, created_at desc);
create index events_project_time on events (project_id, created_at desc)
  where project_id is not null;
create index events_lead_time on events (lead_id, created_at desc)
  where lead_id is not null;

alter sequence events_id_seq owned by events.id;


-- Creates events_YYYY_MM for the month containing p_month. Safe to re-run.
create or replace function create_monthly_partition(p_month date)
returns void
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_start timestamptz := (date_trunc('month', p_month)::date)::timestamp at time zone 'UTC';
  v_end   timestamptz := (date_trunc('month', p_month)::date + interval '1 month')::timestamp at time zone 'UTC';
  v_name  text := 'events_' || to_char(date_trunc('month', p_month), 'YYYY_MM');
begin
  if to_regclass('public.' || v_name) is null then
    execute format(
      'create table %I partition of events for values from (%L) to (%L)',
      v_name, v_start, v_end
    );
  end if;
end;
$$;

select create_monthly_partition((now() at time zone 'UTC')::date);
select create_monthly_partition(((now() at time zone 'UTC') + interval '1 month')::date);
select create_monthly_partition(((now() at time zone 'UTC') + interval '2 months')::date);


-- =============================================================================
-- 10. Returning customers (design §5.7)
-- =============================================================================

create table returning_customers (
  artist_id    uuid not null references artists(id) on delete cascade,
  phone_e164   text not null,
  name         text,
  source       text not null default 'imported'
               check (source in ('imported','deposit_confirmed')),
  confirmed_at timestamptz,
  created_at   timestamptz not null default now(),
  primary key (artist_id, phone_e164)
);


-- =============================================================================
-- 11. Conversation memory (design §5.10) — owned by the n8n memory node
-- =============================================================================

-- n8n's Postgres Chat Memory node (@langchain/community PostgresChatMessageHistory)
-- creates exactly (id serial, session_id varchar(255), message jsonb) on first
-- use and inserts with an explicit column list, so an extra column with a
-- default is safe. `created_at` is what makes retention possible at all: the
-- node stores no timestamp.
create table if not exists chat_memory (
  id         serial primary key,
  session_id varchar(255) not null,
  message    jsonb not null
);

alter table chat_memory
  add column if not exists created_at timestamptz not null default now();

create index if not exists chat_memory_session on chat_memory (session_id, id);
create index if not exists chat_memory_created on chat_memory (created_at);


-- =============================================================================
-- 12. Analytics rollup (design §5.9) — populated by a nightly job
-- =============================================================================

create table artist_daily_metrics (
  artist_id     uuid not null references artists(id) on delete cascade,
  day           date not null,
  leads_new     integer not null default 0,
  projects_new  integer not null default 0,
  quotes_sent   integer not null default 0,
  bookings      integer not null default 0,
  revenue_cents bigint  not null default 0,
  primary key (artist_id, day)
);


-- =============================================================================
-- 13. updated_at triggers (design Appendix A)
-- =============================================================================

create or replace function touch_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create trigger trg_artists_touch before update on artists
  for each row execute function touch_updated_at();
create trigger trg_artist_settings_touch before update on artist_settings
  for each row execute function touch_updated_at();
create trigger trg_leads_touch before update on leads
  for each row execute function touch_updated_at();
create trigger trg_projects_touch before update on projects
  for each row execute function touch_updated_at();
create trigger trg_promotions_touch before update on promotions
  for each row execute function touch_updated_at();
create trigger trg_calendar_entries_touch before update on calendar_entries
  for each row execute function touch_updated_at();
create trigger trg_chat_sessions_touch before update on chat_sessions
  for each row execute function touch_updated_at();

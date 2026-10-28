-- =============================================================================
-- VAIF CRM — v1 → v2 tenant data migration (2026-10-28 cutover)
--
-- Copies the ONLY data worth keeping from the v1 `postgres` database into the
-- fresh v2 database: the Artist config and the price book. v1 Leads/Projects
-- were disposable and are intentionally not migrated.
--
-- Run ON the v2 database, as the owner (postgres), in the same cluster:
--
--   psql -U postgres -d vaif_crm -v ON_ERROR_STOP=1 -f v1_to_v2_tenants.sql
--
-- Requires the `dblink` extension (available in postgres:17-alpine). The script
-- is idempotent: re-running refreshes the tenant config from v1 and leaves the
-- v2 rows otherwise intact.
-- =============================================================================

\set ON_ERROR_STOP on

create extension if not exists dblink;

select dblink_connect('v1', 'dbname=postgres');

-- ---------------------------------------------------------------------------
-- Artists (identity only; everything configurable lives in artist_settings)
-- ---------------------------------------------------------------------------
insert into artists (id, name, status, timezone, wa_session_slug,
                     onboarding_token, created_at, updated_at)
select t.id, t.nome, t.status, t.timezone, t.wa_session_slug,
       t.onboarding_token, t.created_at, t.created_at
from dblink('v1', $q$
  select id, nome, status, timezone, wa_session_slug,
         onboarding_token, created_at
  from artists
$q$) as t(id uuid, nome text, status text, timezone text, wa_session_slug text,
          onboarding_token text, created_at timestamptz)
on conflict (id) do update set
  name            = excluded.name,
  status          = excluded.status,
  timezone        = excluded.timezone,
  wa_session_slug = excluded.wa_session_slug,
  updated_at      = now();

-- ---------------------------------------------------------------------------
-- Artist settings (v1 kept these on the artist row)
-- ---------------------------------------------------------------------------
insert into artist_settings (
  artist_id, display_name, specialties, excluded_styles, floor_pct,
  deposit_type, deposit_value, pix_key, instagram_handle, whatsapp_number,
  telegram_group_id, working_hours, ai_active_hours, pricing_extras
)
select t.id,
       coalesce(t.display_name, t.nome),
       coalesce(t.specialties, '{}'),
       coalesce(t.nao_faco, '{}'),
       t.floor_pct,
       coalesce(t.deposit_type, 'percent'),
       t.deposit_value,
       t.pix_key,
       t.instagram_handle,
       t.whatsapp_number,
       t.telegram_group_id,
       coalesce(t.working_hours, '{}'::jsonb),
       coalesce(t.ai_active_hours, '{}'::jsonb),
       coalesce(t.pricing_extras, '{}'::jsonb)
from dblink('v1', $q$
  select id, nome, display_name, specialties, nao_faco, floor_pct,
         deposit_type, deposit_value, pix_key, instagram_handle, whatsapp_number,
         telegram_group_id, working_hours, ai_active_hours, pricing_extras
  from artists
$q$) as t(id uuid, nome text, display_name text, specialties text[],
          nao_faco text[], floor_pct numeric, deposit_type text,
          deposit_value integer, pix_key text, instagram_handle text,
          whatsapp_number text, telegram_group_id text,
          working_hours jsonb, ai_active_hours jsonb, pricing_extras jsonb)
on conflict (artist_id) do update set
  display_name      = excluded.display_name,
  specialties       = excluded.specialties,
  excluded_styles   = excluded.excluded_styles,
  floor_pct         = excluded.floor_pct,
  deposit_type      = excluded.deposit_type,
  deposit_value     = excluded.deposit_value,
  pix_key           = excluded.pix_key,
  instagram_handle  = excluded.instagram_handle,
  whatsapp_number   = excluded.whatsapp_number,
  telegram_group_id = excluded.telegram_group_id,
  working_hours     = excluded.working_hours,
  ai_active_hours   = excluded.ai_active_hours,
  pricing_extras    = excluded.pricing_extras,
  updated_at        = now();

-- ---------------------------------------------------------------------------
-- Price book (v1 `pricing` → v2 `price_book`, one current row per cell)
-- ---------------------------------------------------------------------------
insert into price_book (artist_id, placement, body_zone, base_price_cents,
                        session_duration_min, buffer_min)
select t.artist_id, t.placement, t.body_zone, t.table_price,
       t.session_duration_min, t.buffer_min
from dblink('v1', $q$
  select artist_id, placement, body_zone, table_price,
         session_duration_min, buffer_min
  from pricing
$q$) as t(artist_id uuid, placement text, body_zone text, table_price integer,
          session_duration_min integer, buffer_min integer)
on conflict (artist_id, placement, body_zone) where effective_to is null
do update set
  base_price_cents     = excluded.base_price_cents,
  session_duration_min = excluded.session_duration_min,
  buffer_min           = excluded.buffer_min;

select dblink_disconnect('v1');

do $$
declare
  v_artists integer;
  v_prices  integer;
begin
  select count(*) into v_artists from artists;
  select count(*) into v_prices from price_book where effective_to is null;
  raise notice 'v1 tenants migrated: % artists, % current price rows', v_artists, v_prices;
end;
$$;

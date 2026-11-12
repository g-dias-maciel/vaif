-- =============================================================================
-- Observe mode — Phase 1
--
-- `reply_mode = 'observe'` makes the agent a silent operator:
--   * it never composes or sends a reply;
--   * it records both sides of the conversation;
--   * it writes the safe, high-confidence facts to the CRM.
--
-- This migration adds the mode, the observed-turn audit table, the per-lead
-- conversation summary, and the write primitive for discovery fields. Quotes,
-- bookings and the review queue are later phases.
-- =============================================================================

alter table artist_settings
  drop constraint if exists artist_settings_reply_mode_check;

alter table artist_settings
  add constraint artist_settings_reply_mode_check
  check (reply_mode in ('off', 'observe', 'draft', 'auto'));

comment on column artist_settings.reply_mode is
  'off = nothing; observe = record and write the CRM, never reply; draft = suggestion only; auto = apply and send.';

-- -----------------------------------------------------------------------------
-- One interpreted unit of conversation. Inbound bursts and artist outbound
-- messages are both recorded here for audit, dedupe and later phases.
-- -----------------------------------------------------------------------------
create table if not exists observed_turns (
  id            uuid primary key default gen_random_uuid(),
  artist_id     uuid not null references artists(id) on delete cascade,
  lead_id       uuid references leads(id) on delete cascade,
  project_id    uuid references projects(id) on delete cascade,
  chat_id       text not null,
  direction     text not null check (direction in ('inbound', 'outbound')),
  wa_message_id text,
  occurred_at   timestamptz not null default now(),
  text          text not null default '',
  text_hash     text not null,
  intent        text,
  entities      jsonb not null default '{}'::jsonb,
  facts         jsonb not null default '{}'::jsonb,
  model         text,
  status        text not null default 'observed',
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create unique index if not exists observed_turns_wa
  on observed_turns (artist_id, chat_id, wa_message_id)
  where wa_message_id is not null;

create unique index if not exists observed_turns_time
  on observed_turns (artist_id, chat_id, direction, occurred_at)
  where wa_message_id is null;

create index if not exists observed_turns_lead
  on observed_turns (artist_id, lead_id, occurred_at desc);

create index if not exists observed_turns_chat
  on observed_turns (artist_id, chat_id, occurred_at desc);

comment on table observed_turns is
  'Audit of every observed turn (inbound or artist outbound); never produces a reply.';

-- -----------------------------------------------------------------------------
-- Rolling conversation summary shown on the Lead page. One row per Lead,
-- updated in place; it is not a user Note.
-- -----------------------------------------------------------------------------
create table if not exists lead_summaries (
  lead_id       uuid primary key references leads(id) on delete cascade,
  body          text not null,
  message_count integer not null default 0,
  model         text,
  generated_at  timestamptz not null default now(),
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

comment on table lead_summaries is
  'AI conversation summary for the Lead page; updated in place, artist-facing.';

-- -----------------------------------------------------------------------------
-- Write discovery fields that the speaker stated explicitly. The classifier
-- already demotes inferred values to `assumptions`; this function only sees
-- `entities`.
--
-- Only moves `novo` → `qualificando`; it never closes, books or quotes.
-- -----------------------------------------------------------------------------
create or replace function observe_project_fields(
  p_artist_id  uuid,
  p_project_id uuid,
  p_phone      text,
  p_entities   jsonb,
  p_origin     text default 'observed'
)
returns jsonb
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_before  projects;
  v_after   projects;
  v_prev    text;
  v_changed text[] := '{}';
  v_placement text;
  v_body_zone text;
  v_style     text;
  v_meaning   text;
  v_first     boolean;
  v_type      text;
  v_type_pt   text;
  v_name      text;
begin
  select * into v_before
    from projects
   where id = p_project_id and artist_id = p_artist_id
   for update;

  if not found then
    return jsonb_build_object('error', 'project_not_found');
  end if;

  v_prev := v_before.stage_key;

  v_placement := nullif(btrim(coalesce(p_entities->>'placement', '')), '');
  v_body_zone := nullif(btrim(coalesce(p_entities->>'body_zone', '')), '');
  v_style     := nullif(btrim(coalesce(p_entities->>'style', '')), '');
  v_meaning   := nullif(btrim(coalesce(p_entities->>'meaning', '')), '');

  if (p_entities ? 'first_tattoo')
     and p_entities->>'first_tattoo' in ('true', 'false') then
    v_first := (p_entities->>'first_tattoo')::boolean;
  end if;

  v_type_pt := lower(coalesce(nullif(p_entities->>'tattoo_type', ''), nullif(p_entities->>'tipo', '')));
  v_type := case v_type_pt
    when 'nova' then 'new'
    when 'cobertura' then 'cover_up'
    when 'reforma' then 'rework'
    else null
  end;

  perform patch_project(
    p_artist_id, p_project_id,
    null, v_placement, v_body_zone, v_style, v_first, v_meaning, v_type,
    null, null, p_origin, v_prev
  );

  select * into v_after
    from projects
   where id = p_project_id and artist_id = p_artist_id;

  if v_after.placement is distinct from v_before.placement then
    v_changed := array_append(v_changed, 'placement');
  end if;
  if v_after.body_zone is distinct from v_before.body_zone then
    v_changed := array_append(v_changed, 'body_zone');
  end if;
  if v_after.style is distinct from v_before.style then
    v_changed := array_append(v_changed, 'style');
  end if;
  if v_after.first_tattoo is distinct from v_before.first_tattoo then
    v_changed := array_append(v_changed, 'first_tattoo');
  end if;
  if v_after.meaning is distinct from v_before.meaning then
    v_changed := array_append(v_changed, 'meaning');
  end if;
  if v_after.tattoo_type is distinct from v_before.tattoo_type then
    v_changed := array_append(v_changed, 'tattoo_type');
  end if;

  if array_length(v_changed, 1) is not null and v_prev = 'novo' then
    perform set_project_stage(p_artist_id, p_project_id, 'qualificando', p_origin);
    select * into v_after
      from projects
     where id = p_project_id and artist_id = p_artist_id;
  end if;

  v_name := nullif(btrim(coalesce(p_entities->>'name', '')), '');
  if v_name is not null and p_phone is not null and btrim(p_phone) <> '' then
    perform set_lead_name(p_artist_id, p_phone, v_name);
  end if;

  return jsonb_build_object(
    'changed', to_jsonb(v_changed),
    'stage_key', v_after.stage_key
  );
end;
$$;

revoke all on function observe_project_fields(uuid, uuid, text, jsonb, text) from public;
grant execute on function observe_project_fields(uuid, uuid, text, jsonb, text) to crm_app;

grant select, insert, update, delete on observed_turns to crm_app;
grant select on observed_turns to analytics_ro;

grant select, insert, update, delete on lead_summaries to crm_app;
grant select on lead_summaries to analytics_ro;

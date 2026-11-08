-- =============================================================================
-- Grounded turns — Pass 0: contracts + shadow logging
--
-- The agent is moving to a grounded-generation architecture:
--
--   preflight intent (AI) -> authoritative facts (SQL) -> composed reply (AI)
--   -> structured claims (AI) -> validation against facts (code)
--   -> backend action.
--
-- This migration adds the two tables/columns that architecture needs without
-- changing a single reply:
--
--   * `artist_settings.grounding_mode` — off | shadow | enforce, per artist;
--   * `agent_turn_log` — one row per turn with intent, facts, claims,
--     violations and resolution. Shadow mode fills it so the contracts can be
--     evaluated on real traffic before enforcement.
--
-- `build_turn_facts()` is the single source of truth for what the agent may
-- assert: price row, open quote, promotion, deposit, real slots, active
-- booking, returning-customer status and studio data.
-- =============================================================================

alter table artist_settings
  add column if not exists grounding_mode text not null default 'enforce';

alter table artist_settings
  drop constraint if exists artist_settings_grounding_mode_check;

alter table artist_settings
  add constraint artist_settings_grounding_mode_check
  check (grounding_mode in ('off', 'shadow', 'enforce'));

comment on column artist_settings.grounding_mode is
  'off = legacy flow; shadow = run grounding and log only; enforce = grounded reply is the one sent.';

-- Every existing artist moves to the grounded path; suspension still wins in
-- the workflow, so nothing is answered until an artist is deliberately activated.
update artist_settings set grounding_mode = 'enforce' where grounding_mode is distinct from 'enforce';


create table if not exists agent_turn_log (
  id                uuid primary key default gen_random_uuid(),
  turn_id           uuid not null,
  artist_id         uuid references artists(id) on delete cascade,
  lead_id           uuid,
  project_id        uuid,
  chat_id           text,
  mode              text not null default 'shadow',
  intent            text,
  intent_confidence numeric(3, 2),
  facts             jsonb not null default '{}'::jsonb,
  reply             text,
  claims            jsonb not null default '[]'::jsonb,
  violations        jsonb not null default '[]'::jsonb,
  resolution        text,
  model             text,
  latency_ms        integer,
  created_at        timestamptz not null default now()
);

create index if not exists agent_turn_log_artist_time
  on agent_turn_log (artist_id, created_at desc);

create index if not exists agent_turn_log_turn
  on agent_turn_log (turn_id);

create index if not exists agent_turn_log_violations
  on agent_turn_log (created_at desc)
  where jsonb_array_length(violations) > 0;

comment on table agent_turn_log is
  'Audit of every grounded turn: AI intent, authoritative facts, AI claims, code violations. Shadow rows never affect the reply.';


-- -----------------------------------------------------------------------------
-- build_turn_facts — the facts block, from the database only.
--
-- `p_entities` is the preflight classifier output. `requested_day` and
-- `requested_window` only filter availability; every value returned here is
-- traceable to a table or a SECURITY DEFINER function.
-- -----------------------------------------------------------------------------
create or replace function build_turn_facts(
  p_artist_id uuid,
  p_project_id uuid,
  p_phone     text,
  p_intent    text default null,
  p_entities  jsonb default '{}'::jsonb
)
returns jsonb
language plpgsql stable security definer
set search_path = public, pg_temp
as $$
declare
  v_project     projects;
  v_phone       text := normalize_phone(p_phone);
  v_base        integer;
  v_open_quote  integer;
  v_promo       jsonb;
  v_dep_type    text;
  v_dep_value   integer;
  v_pix         text;
  v_city        text;
  v_address     text;
  v_hours       jsonb;
  v_deposit     integer;
  v_day         text := nullif(btrim(coalesce(p_entities->>'requested_day', '')), '');
  v_window      text := nullif(btrim(coalesce(p_entities->>'requested_window', '')), '');
  v_av_status   text;
  v_av_missing  text;
  v_slots       jsonb;
  v_booking_id  uuid;
  v_booking_at  timestamptz;
  v_created     boolean := false;
  v_returning   boolean := false;
  v_lead_name   text;
  v_timezone    text;
  v_placement   text;
  v_body_zone   text;
  v_style       text;
  v_type        text;
begin
  select * into v_project
  from projects
  where id = p_project_id and artist_id = p_artist_id;

  if not found then
    return jsonb_build_object('error', 'project_not_found');
  end if;

  -- The preflight may have extracted fields from THIS message. Use them for
  -- the price lookup and for the project block, before the write happens.
  v_placement := coalesce(nullif(btrim(coalesce(p_entities->>'placement', '')), ''), v_project.placement);
  v_body_zone := coalesce(nullif(btrim(coalesce(p_entities->>'body_zone', '')), ''), v_project.body_zone);
  v_style     := coalesce(nullif(btrim(coalesce(p_entities->>'style', '')), ''), v_project.style);
  v_type      := case lower(coalesce(nullif(p_entities->>'tattoo_type', ''), nullif(p_entities->>'tipo', '')))
    when 'nova' then 'new'
    when 'cobertura' then 'cover_up'
    when 'reforma' then 'rework'
    else v_project.tattoo_type
  end;

  select pb.base_price_cents into v_base
  from price_book pb
  where pb.artist_id = p_artist_id
    and pb.placement = v_placement
    and pb.body_zone = v_body_zone
    and pb.effective_to is null
  order by pb.effective_from desc
  limit 1;

  select q.offered_price_cents into v_open_quote
  from quotes q
  where q.artist_id = p_artist_id
    and q.project_id = p_project_id
    and q.status = 'open'
    and (q.valid_until is null or q.valid_until > now())
  order by q.created_at desc
  limit 1;

  select jsonb_build_object(
           'name', p.name,
           'multiplier', p.multiplier,
           'price_cents', pp.base_price_cents
         )
    into v_promo
  from promotions p
  left join promotion_prices pp
    on pp.promotion_id = p.id
   and pp.placement = v_placement
   and pp.body_zone = v_body_zone
  where p.artist_id = p_artist_id
    and p.status = 'active'
  limit 1;

  select s.deposit_type, s.deposit_value, s.pix_key, s.city, s.address, s.working_hours
    into v_dep_type, v_dep_value, v_pix, v_city, v_address, v_hours
  from artist_settings s
  where s.artist_id = p_artist_id;

  v_dep_type := coalesce(v_dep_type, 'percent');
  v_dep_value := coalesce(v_dep_value, 0);

  v_deposit := case
    when v_dep_type = 'fixed' then round(v_dep_value * 100)::integer
    else ceil(coalesce(v_base, v_open_quote, 0) * v_dep_value / 100.0)::integer
  end;

  -- Availability: always exactly the rows the lead may be offered.
  select
    case when bool_or(o.start_at is not null) then 'ready' else max(o.status) end,
    max(o.missing),
    coalesce(
      jsonb_agg(
        jsonb_build_object('id', 'F' || o.rn, 'start_at', o.start_at, 'label', o.label)
        order by o.start_at
      ) filter (where o.start_at is not null),
      '[]'::jsonb
    )
    into v_av_status, v_av_missing, v_slots
  from (
    select b.*,
           row_number() over (order by b.start_at) as rn
    from booking_options_v2(
      p_artist_id, p_project_id, now(), now() + interval '60 days',
      120, v_day, v_window
    ) b
  ) o;

  select c.id, c.start_at
    into v_booking_id, v_booking_at
  from calendar_entries c
  join leads l on l.id = c.lead_id and l.artist_id = c.artist_id
  where c.artist_id = p_artist_id
    and c.kind = 'booking'
    and c.active
    and l.phone_e164 = v_phone
  order by c.start_at desc
  limit 1;

  select exists (
    select 1 from calendar_entries c
    where c.artist_id = p_artist_id
      and c.project_id = p_project_id
      and c.kind = 'booking'
      and c.active
      and (c.created_at >= now() - interval '3 minutes'
           or c.updated_at >= now() - interval '3 minutes')
  ) into v_created;

  select l.name into v_lead_name
  from leads l
  where l.id = v_project.lead_id;

  select a.timezone into v_timezone
  from artists a
  where a.id = p_artist_id;

  select exists (
    select 1 from returning_customers rc
    where rc.artist_id = p_artist_id and rc.phone_e164 = v_phone
  ) into v_returning;

  return jsonb_build_object(
    'turn', jsonb_build_object(
      'intent', p_intent,
      'entities', coalesce(p_entities, '{}'::jsonb)
    ),
    'availability', jsonb_build_object(
      'status', coalesce(v_av_status, 'no_slots'),
      'missing', v_av_missing,
      'slots', coalesce(v_slots, '[]'::jsonb)
    ),
    'pricing', jsonb_build_object(
      'status', case
        when v_base is null and v_open_quote is null and v_promo is null then 'no_price_row'
        else 'ready'
      end,
      'base_cents', v_base,
      'offered_cents', coalesce(v_open_quote, v_base),
      'deposit_cents', v_deposit,
      'open_quote', (v_open_quote is not null),
      'promotion', v_promo
    ),
    'conditions', jsonb_build_object(
      'deposit_type', v_dep_type,
      'deposit_value', v_dep_value,
      'pix_available', (v_pix is not null and btrim(v_pix) <> '')
    ),
    'booking', jsonb_build_object(
      'active', v_booking_id is not null,
      'start_at', v_booking_at,
      'created_this_turn', v_created
    ),
    'project', jsonb_build_object(
      'placement', v_placement,
      'body_zone', v_body_zone,
      'style', v_style,
      'tattoo_type', v_type,
      'first_tattoo', v_project.first_tattoo,
      'meaning', v_project.meaning
    ),
    'client', jsonb_build_object(
      'name', v_lead_name,
      'returning', v_returning,
      'aftercare', (p_intent = 'aftercare')
    ),
    'studio', jsonb_build_object(
      'city', v_city,
      'address', v_address,
      'timezone', coalesce(v_timezone, 'America/Sao_Paulo'),
      'working_hours', coalesce(v_hours, '{}'::jsonb)
    )
  );
end;
$$;

revoke all on function build_turn_facts(uuid, uuid, text, text, jsonb) from public;
grant execute on function build_turn_facts(uuid, uuid, text, text, jsonb) to agent_rw, crm_app;


-- -----------------------------------------------------------------------------
-- log_agent_turn — one audit row per turn. Shadow rows are evidence, not
-- control flow.
-- -----------------------------------------------------------------------------
create or replace function log_agent_turn(
  p_turn_id     uuid,
  p_artist_id   uuid,
  p_lead_id     uuid,
  p_project_id  uuid,
  p_chat_id     text,
  p_mode        text,
  p_intent      text,
  p_confidence  numeric,
  p_facts       jsonb,
  p_reply       text,
  p_claims      jsonb,
  p_violations  jsonb,
  p_resolution  text,
  p_model       text,
  p_latency_ms  integer
)
returns uuid
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_id uuid;
begin
  insert into agent_turn_log (
    turn_id, artist_id, lead_id, project_id, chat_id, mode, intent,
    intent_confidence, facts, reply, claims, violations, resolution, model,
    latency_ms
  ) values (
    coalesce(p_turn_id, gen_random_uuid()), p_artist_id, p_lead_id, p_project_id,
    p_chat_id, coalesce(p_mode, 'shadow'), p_intent, p_confidence,
    coalesce(p_facts, '{}'::jsonb), p_reply, coalesce(p_claims, '[]'::jsonb),
    coalesce(p_violations, '[]'::jsonb), p_resolution, p_model, p_latency_ms
  )
  returning id into v_id;

  return v_id;
end;
$$;

revoke all on function log_agent_turn(uuid, uuid, uuid, uuid, text, text, text, numeric, jsonb, text, jsonb, jsonb, text, text, integer) from public;
grant execute on function log_agent_turn(uuid, uuid, uuid, uuid, text, text, text, numeric, jsonb, text, jsonb, jsonb, text, text, integer) to agent_rw, crm_app;

grant select on agent_turn_log to crm_app, analytics_ro;

-- -----------------------------------------------------------------------------
-- v_agent_turn_quality — the shadow-mode read model.
--
-- One row per artist/day/mode. `turns_with_violations` is the number of replies
-- that would have been rejected under enforcement; `turns_without_claims` is the
-- claim extractor's blind spot. Both should go to zero before enforcing.
-- -----------------------------------------------------------------------------
create or replace view v_agent_turn_quality as
  select
    (created_at at time zone 'America/Sao_Paulo')::date as day,
    artist_id,
    mode,
    count(*)                                                          as turns,
    count(*) filter (where jsonb_array_length(violations) > 0)        as turns_with_violations,
    count(*) filter (where jsonb_array_length(claims) = 0)            as turns_without_claims,
    round(avg(intent_confidence), 2)                                  as avg_confidence
  from agent_turn_log
  group by 1, 2, 3;

grant select on v_agent_turn_quality to crm_app, analytics_ro;

-- =============================================================================
-- Availability truth + reschedule support
--
-- Two production failures motivated this migration:
--
--   1. `Check Availability` wrapped `booking_options()` in
--      `WHERE project_booking_ready(...)`. When the project was missing
--      placement/body_zone the query returned ZERO rows, and the agent read
--      that as "the agenda is full". The new `booking_options_v2()` returns an
--      explicit status (`ready` / `not_ready` / `no_slots` / `error`) so an
--      incomplete project can never produce a false "no availability".
--
--   2. A reschedule created a brand-new project, so `get_active_booking()`
--      (project-scoped) saw nothing. `start_turn` now also returns the active
--      booking for the PHONE, and the existing `reschedule_booking()` moves
--      that booking instead of creating a second one.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- booking_options_v2 — one row always, with a status the model can trust.
-- `missing` names the qualification fields that block scheduling.
-- -----------------------------------------------------------------------------
create or replace function booking_options_v2(
  p_artist_id    uuid,
  p_project_id   uuid,
  p_from         timestamptz,
  p_to           timestamptz,
  p_duration_min integer default 120,
  p_day_text     text default null,
  p_time_text    text default null
)
returns table (
  status   text,
  missing  text,
  start_at timestamptz,
  end_at   timestamptz,
  label    text
)
language plpgsql stable security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_missing text[] := '{}';
  v_count   integer := 0;
begin
  select * into v_project
  from projects
  where id = p_project_id and artist_id = p_artist_id;

  if not found then
    return query select 'error'::text, 'project'::text,
      null::timestamptz, null::timestamptz, null::text;
    return;
  end if;

  if v_project.tattoo_type is null then
    v_missing := array_append(v_missing, 'tipo');
  end if;
  if v_project.placement is null then
    v_missing := array_append(v_missing, 'local');
  end if;
  if v_project.body_zone is null then
    v_missing := array_append(v_missing, 'tamanho');
  end if;

  if array_length(v_missing, 1) > 0 then
    return query select 'not_ready'::text, array_to_string(v_missing, ',')::text,
      null::timestamptz, null::timestamptz, null::text;
    return;
  end if;

  return query
    select 'ready'::text, null::text, o.start_at, o.end_at, o.label
    from booking_options(
      p_artist_id,
      p_from,
      p_to,
      coalesce(p_duration_min, 120),
      p_day_text,
      p_time_text
    ) o;

  get diagnostics v_count = row_count;

  if v_count = 0 then
    -- A genuinely empty agenda, not an unqualified project.
    return query select 'no_slots'::text, null::text,
      null::timestamptz, null::timestamptz, null::text;
  end if;
end;
$$;

revoke all on function booking_options_v2(uuid, uuid, timestamptz, timestamptz, integer, text, text) from public;
grant execute on function booking_options_v2(uuid, uuid, timestamptz, timestamptz, integer, text, text) to agent_rw, crm_app;


-- -----------------------------------------------------------------------------
-- get_active_booking_by_phone — reschedule lookup that does not depend on the
-- (new) project the agent just created. Always returns exactly one row so an
-- n8n Postgres node never emits zero items and stops the workflow.
-- -----------------------------------------------------------------------------
create or replace function get_active_booking_by_phone(
  p_artist_id uuid,
  p_phone     text
)
returns table (
  id         uuid,
  project_id uuid,
  start_at   timestamptz,
  end_at     timestamptz
)
language sql stable security definer
set search_path = public, pg_temp
as $$
  with phone as (
    select normalize_phone(p_phone) as e164
  )
  select ce.id, ce.project_id, ce.start_at, ce.end_at
  from (select 1) as one
  cross join phone ph
  left join lateral (
    select c.id, c.project_id, c.start_at, c.end_at
    from calendar_entries c
    join leads l on l.id = c.lead_id and l.artist_id = c.artist_id
    where c.artist_id = p_artist_id
      and c.kind = 'booking'
      and c.active
      and l.phone_e164 = ph.e164
    order by c.start_at desc
    limit 1
  ) ce on true;
$$;

revoke all on function get_active_booking_by_phone(uuid, text) from public;
grant execute on function get_active_booking_by_phone(uuid, text) to agent_rw, crm_app;


-- -----------------------------------------------------------------------------
-- start_turn — add the phone-level active booking so the agent can detect a
-- reschedule before it re-qualifies the lead.
--
-- Adding OUT columns changes the return type, which CREATE OR REPLACE cannot
-- do; drop and recreate. Every caller uses `SELECT *` or named fields.
-- -----------------------------------------------------------------------------
drop function if exists start_turn(uuid, text, text, text, text);

create or replace function start_turn(
  p_artist_id uuid,
  p_chat_id   text,
  p_phone     text,
  p_name      text default null,
  p_source    text default null
)
returns table (
  lead_id            uuid,
  project_id         uuid,
  is_new             boolean,
  name               text,
  phone_e164         text,
  stage_key          text,
  placement          text,
  body_zone          text,
  style              text,
  first_tattoo       boolean,
  meaning            text,
  tattoo_type        text,
  deposit_status     text,
  deposit_amount_cents integer,
  table_price_cents  integer,
  quoted_price_cents integer,
  agent_state        jsonb,
  reference_analysis jsonb,
  agent_blocked      boolean,
  active_booking_id    uuid,
  active_booking_start timestamptz,
  active_booking_end   timestamptz
)
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_phone    text := normalize_phone(p_phone);
  v_lead     leads;
  v_project  projects;
  v_quoted   integer;
  v_table    integer;
  v_is_new   boolean;
  v_state    jsonb := '{}'::jsonb;
  v_pay_status text;
  v_pay_cents  integer;
  v_active_id    uuid;
  v_active_start timestamptz;
  v_active_end   timestamptz;
begin
  if v_phone is null then
    raise exception 'start_turn: invalid phone' using errcode = '22023';
  end if;
  if p_chat_id is null or btrim(p_chat_id) = '' then
    raise exception 'start_turn: chat_id is required' using errcode = '22023';
  end if;

  -- Serialise turns of the same conversation: `is_new` stays exact and two
  -- concurrent executions cannot both open a Project.
  perform pg_advisory_xact_lock(hashtext(p_artist_id::text || ':' || v_phone)::bigint);

  v_is_new := not exists (
    select 1 from leads l
     where l.artist_id = p_artist_id and l.phone_e164 = v_phone
  );

  select * into v_lead from upsert_lead(p_artist_id, v_phone, p_name, p_source);
  select * into v_project from upsert_project(p_artist_id, v_lead.id, 'agent');

  perform link_channel(p_artist_id, v_lead.id, 'whatsapp', p_chat_id);
  perform resolve_chat_session(p_artist_id, p_chat_id, v_lead.id);

  select cs.agent_state into v_state
    from chat_sessions cs
   where cs.artist_id = p_artist_id and cs.chat_id = p_chat_id;

  select q.offered_price_cents into v_quoted
    from quotes q
   where q.artist_id = p_artist_id
     and q.project_id = v_project.id
     and q.status = 'open'
     and (q.valid_until is null or q.valid_until > now())
   order by q.created_at desc
   limit 1;

  select pay.status, pay.amount_cents into v_pay_status, v_pay_cents
    from payments pay
   where pay.artist_id = p_artist_id
     and pay.project_id = v_project.id
     and pay.kind = 'deposit'
   order by pay.requested_at desc
   limit 1;

  select pb.base_price_cents into v_table
    from price_book pb
   where pb.artist_id = p_artist_id
     and pb.placement = v_project.placement
     and pb.body_zone = v_project.body_zone
     and pb.effective_to is null
   limit 1;

  -- Active booking for this phone, across every project. This is what lets the
  -- agent recognise "preciso remarcar" instead of treating it as a new lead.
  select c.id, c.start_at, c.end_at
    into v_active_id, v_active_start, v_active_end
    from calendar_entries c
    join leads l2 on l2.id = c.lead_id and l2.artist_id = c.artist_id
   where c.artist_id = p_artist_id
     and c.kind = 'booking'
     and c.active
     and l2.phone_e164 = v_phone
   order by c.start_at desc
   limit 1;

  lead_id            := v_lead.id;
  project_id         := v_project.id;
  is_new             := v_is_new;
  name               := v_lead.name;
  phone_e164         := v_lead.phone_e164;
  stage_key          := v_project.stage_key;
  placement          := v_project.placement;
  body_zone          := v_project.body_zone;
  style              := v_project.style;
  first_tattoo       := v_project.first_tattoo;
  meaning            := v_project.meaning;
  tattoo_type        := v_project.tattoo_type;
  deposit_status     := case v_pay_status
                          when 'confirmed' then 'confirmado'
                          when 'requested' then 'aguardando_confirmacao'
                          else 'nao_solicitado'
                        end;
  deposit_amount_cents := v_pay_cents;
  table_price_cents  := v_table;
  quoted_price_cents := v_quoted;
  agent_state        := coalesce(v_state, '{}'::jsonb);
  reference_analysis := v_project.reference_analysis;
  -- Per-lead flag OR number-level blocklist. Digits-only comparison, the same
  -- normalisation the workflow uses.
  agent_blocked      := v_lead.agent_blocked_at is not null
    OR exists (
      select 1
        from artist_settings s
        cross join lateral unnest(s.blocked_numbers) as b
       where s.artist_id = p_artist_id
         and regexp_replace(b, '[^0-9]', '', 'g')
             = regexp_replace(v_phone, '[^0-9]', '', 'g')
    );
  active_booking_id    := v_active_id;
  active_booking_start := v_active_start;
  active_booking_end   := v_active_end;

  return next;
end;
$$;

revoke all on function start_turn(uuid, text, text, text, text) from public;
grant execute on function start_turn(uuid, text, text, text, text) to agent_rw, crm_app;

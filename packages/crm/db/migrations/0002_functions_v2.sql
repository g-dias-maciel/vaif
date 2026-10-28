-- =============================================================================
-- VAIF CRM — v2 RPC surface (design §8)
--
-- Every function:
--   * is SECURITY DEFINER with a pinned search_path,
--   * takes p_artist_id and validates that every referenced row belongs to it,
--   * writes an `events` row in the same transaction for mutations,
--   * returns the changed row so n8n does not need a second read.
--
-- The agent (n8n) and the CRM both call these; neither writes tables directly.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Helpers
-- -----------------------------------------------------------------------------

-- Brazilian numbers arrive as "11987654321", "+55 11 98765-4321" or a WAHA
-- "@lid" identifier. Returns E.164 with a leading +, or the opaque id unchanged
-- (a lid is not a phone number and must not be mangled).
create or replace function normalize_phone(p_phone text)
returns text
language plpgsql immutable
as $$
declare
  v        text := btrim(coalesce(p_phone, ''));
  v_digits text;
begin
  if v = '' then
    return null;
  end if;
  if position('@' in v) > 0 then
    return lower(v);
  end if;

  v_digits := regexp_replace(v, '[^0-9]', '', 'g');
  if v_digits = '' then
    return null;
  end if;
  -- Local BR number (DDD + subscriber) without country code.
  if length(v_digits) in (10, 11) then
    v_digits := '55' || v_digits;
  end if;

  return '+' || v_digits;
end;
$$;


-- Internal: append to the audit trail. Never put phone/name in the payload.
create or replace function log_event(
  p_artist_id  uuid,
  p_lead_id    uuid,
  p_project_id uuid,
  p_actor      text,
  p_type       text,
  p_payload    jsonb default '{}'::jsonb
)
returns void
language plpgsql security definer
set search_path = public, pg_temp
as $$
begin
  insert into events (artist_id, lead_id, project_id, actor, type, payload)
  values (p_artist_id, p_lead_id, p_project_id, p_actor, p_type, coalesce(p_payload, '{}'::jsonb));
end;
$$;


-- -----------------------------------------------------------------------------
-- Tenant resolution
-- -----------------------------------------------------------------------------

-- n8n resolves the Artist from the WAHA session slug on every inbound message.
create or replace function resolve_artist(p_slug text)
returns setof jsonb
language sql stable security definer
set search_path = public, pg_temp
as $$
  select jsonb_build_object('artist', to_jsonb(a), 'settings', to_jsonb(s))
  from artists a
  left join artist_settings s on s.artist_id = a.id
  where a.wa_session_slug = p_slug
    and a.status in ('stub','onboarding','live')
  limit 1;
$$;


-- -----------------------------------------------------------------------------
-- Leads and channels
-- -----------------------------------------------------------------------------

create or replace function upsert_lead(
  p_artist_id uuid,
  p_phone     text,
  p_name      text default null,
  p_source    text default null
)
returns setof leads
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_phone text := normalize_phone(p_phone);
  v_lead  leads;
begin
  if v_phone is null then
    raise exception 'upsert_lead: invalid phone' using errcode = '22023';
  end if;
  if not exists (select 1 from artists where id = p_artist_id) then
    raise exception 'upsert_lead: unknown artist %', p_artist_id using errcode = '23503';
  end if;

  insert into leads (artist_id, name, phone_e164, phone_raw, source, last_message_at)
  values (p_artist_id, nullif(btrim(coalesce(p_name, '')), ''), v_phone,
          nullif(btrim(coalesce(p_phone, '')), ''), p_source, now())
  on conflict (artist_id, phone_e164) do update set
    name            = coalesce(excluded.name, leads.name),
    phone_raw       = coalesce(excluded.phone_raw, leads.phone_raw),
    source          = coalesce(leads.source, excluded.source),
    last_message_at = now()
  returning * into v_lead;

  return next v_lead;
end;
$$;


-- Binds a WAHA/IG chat to a Lead. The same external_id may exist for another
-- Artist without colliding (tenant-scoped primary key).
create or replace function link_channel(
  p_artist_id   uuid,
  p_lead_id     uuid,
  p_channel     text,
  p_external_id text
)
returns void
language plpgsql security definer
set search_path = public, pg_temp
as $$
begin
  insert into lead_channels (artist_id, lead_id, channel, external_id)
  values (p_artist_id, p_lead_id, p_channel, p_external_id)
  on conflict (artist_id, channel, external_id) do update
    set lead_id = excluded.lead_id;
end;
$$;


-- -----------------------------------------------------------------------------
-- Chat sessions (replaces message_buffer/chat_control keys)
-- -----------------------------------------------------------------------------

create or replace function resolve_chat_session(
  p_artist_id uuid,
  p_chat_id   text,
  p_lead_id   uuid
)
returns setof chat_sessions
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row chat_sessions;
begin
  if not exists (
    select 1 from leads where id = p_lead_id and artist_id = p_artist_id
  ) then
    raise exception 'resolve_chat_session: lead % does not belong to artist %',
      p_lead_id, p_artist_id using errcode = '23503';
  end if;

  insert into chat_sessions (artist_id, chat_id, lead_id, last_inbound_at)
  values (p_artist_id, p_chat_id, p_lead_id, now())
  on conflict (artist_id, chat_id) do update set
    lead_id         = excluded.lead_id,
    last_inbound_at = now()
  returning * into v_row;

  return next v_row;
end;
$$;


create or replace function take_chat_control(
  p_artist_id uuid,
  p_chat_id   text,
  p_lead_id   uuid default null
)
returns setof chat_sessions
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_lead_id uuid := p_lead_id;
  v_row     chat_sessions;
begin
  if v_lead_id is null then
    select lead_id into v_lead_id
    from lead_channels
    where artist_id = p_artist_id and channel = 'whatsapp' and external_id = p_chat_id;
  end if;

  -- Last resort: derive the phone from the "session:phone" chat id convention.
  if v_lead_id is null then
    select id into v_lead_id
    from upsert_lead(p_artist_id, split_part(p_chat_id, ':', 2), null, 'whatsapp');
  end if;

  insert into chat_sessions (artist_id, chat_id, lead_id, owner, taken_at, returned_at)
  values (p_artist_id, p_chat_id, v_lead_id, 'artist', now(), null)
  on conflict (artist_id, chat_id) do update set
    owner       = 'artist',
    taken_at    = now(),
    returned_at = null
  returning * into v_row;

  return next v_row;
end;
$$;


create or replace function return_chat_control(
  p_artist_id uuid,
  p_chat_id   text
)
returns setof chat_sessions
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row chat_sessions;
begin
  update chat_sessions
     set owner = 'agent', returned_at = now()
   where artist_id = p_artist_id and chat_id = p_chat_id
  returning * into v_row;

  if v_row.chat_id is not null then
    return next v_row;
  end if;
end;
$$;


create or replace function chat_owner(
  p_artist_id uuid,
  p_chat_id   text
)
returns text
language sql stable security definer
set search_path = public, pg_temp
as $$
  select coalesce(
    (select owner from chat_sessions
      where artist_id = p_artist_id and chat_id = p_chat_id),
    'agent'
  );
$$;


-- -----------------------------------------------------------------------------
-- Debounce buffer (chat_buffers)
--
-- The agent answers a burst of WhatsApp messages once, after a quiet window.
-- Redis is the intended store for that pending text; when the deployment has no
-- app Redis (the current Coolify setup) this table is the fallback described in
-- the design. Rows are tenant-scoped and expire after an hour.
-- -----------------------------------------------------------------------------

-- Appends an inbound message to the chat's pending burst.
create or replace function chat_buffer_accumulate(
  p_artist_id uuid,
  p_chat_id   text,
  p_text      text,
  p_msg_at    timestamptz default now()
)
returns setof chat_buffers
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row chat_buffers;
begin
  if not exists (select 1 from artists where id = p_artist_id) then
    raise exception 'chat_buffer_accumulate: unknown artist %', p_artist_id
      using errcode = '23503';
  end if;

  insert into chat_buffers (artist_id, chat_id, pending, last_msg_at, expires_at)
  values (p_artist_id, p_chat_id, nullif(p_text, ''),
          coalesce(p_msg_at, now()), now() + interval '1 hour')
  on conflict (artist_id, chat_id) do update set
    pending = case
                when nullif(excluded.pending, '') is null then chat_buffers.pending
                when nullif(chat_buffers.pending, '') is null then excluded.pending
                else chat_buffers.pending || E'\n' || excluded.pending
              end,
    last_msg_at = greatest(
                    coalesce(chat_buffers.last_msg_at, excluded.last_msg_at),
                    coalesce(excluded.last_msg_at, now())
                  ),
    expires_at  = now() + interval '1 hour'
  returning * into v_row;

  return next v_row;
end;
$$;


-- Records a WAHA presence event (`typing` / `paused`) for the chat.
create or replace function chat_buffer_typing(
  p_artist_id uuid,
  p_chat_id   text,
  p_state     text,
  p_at        timestamptz default now()
)
returns setof chat_buffers
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row chat_buffers;
begin
  if not exists (select 1 from artists where id = p_artist_id) then
    raise exception 'chat_buffer_typing: unknown artist %', p_artist_id
      using errcode = '23503';
  end if;

  insert into chat_buffers (artist_id, chat_id, last_typing_at, typing_state, expires_at)
  values (p_artist_id, p_chat_id, coalesce(p_at, now()), p_state,
          now() + interval '1 hour')
  on conflict (artist_id, chat_id) do update set
    last_typing_at = greatest(
                       coalesce(chat_buffers.last_typing_at, excluded.last_typing_at),
                       coalesce(excluded.last_typing_at, now())
                     ),
    typing_state   = excluded.typing_state,
    expires_at     = now() + interval '1 hour'
  returning * into v_row;

  return next v_row;
end;
$$;


-- Current buffer state; no row means "nothing pending".
create or replace function chat_buffer_state(
  p_artist_id uuid,
  p_chat_id   text
)
returns setof chat_buffers
language sql stable security definer
set search_path = public, pg_temp
as $$
  select * from chat_buffers
   where artist_id = p_artist_id and chat_id = p_chat_id;
$$;


-- Drops the pending burst after the agent answers. The row (and its
-- last_msg_at) stays so a still-running execution can tell it is stale.
create or replace function chat_buffer_clear(
  p_artist_id uuid,
  p_chat_id   text
)
returns setof chat_buffers
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row chat_buffers;
begin
  update chat_buffers
     set pending = null, typing_state = null
   where artist_id = p_artist_id and chat_id = p_chat_id
  returning * into v_row;

  if v_row.chat_id is not null then
    return next v_row;
  end if;
end;
$$;


-- Maintenance: drops abandoned buffers (SDR disabled mid-burst, crashed run).
create or replace function purge_chat_buffers()
returns integer
language sql security definer
set search_path = public, pg_temp
as $$
  with gone as (
    delete from chat_buffers where expires_at < now() returning 1
  )
  select count(*)::integer from gone;
$$;


-- -----------------------------------------------------------------------------
-- Agent scratch state (chat_sessions.agent_state)
--
-- The SDR keeps its sales-gate flags between turns: whether the creative
-- process was explained, whether the price gate is open, whether the doubts
-- checkpoint is pending, how many audios arrived. They are prompt state, not
-- domain data, so they stay in one jsonb column instead of growing the schema.
-- -----------------------------------------------------------------------------

create or replace function patch_chat_state(
  p_artist_id uuid,
  p_chat_id   text,
  p_patch     jsonb
)
returns jsonb
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_state jsonb;
begin
  if p_patch is null or jsonb_typeof(p_patch) <> 'object' then
    raise exception 'patch_chat_state: patch must be a jsonb object'
      using errcode = '22023';
  end if;

  update chat_sessions
     set agent_state = agent_state || p_patch
   where artist_id = p_artist_id and chat_id = p_chat_id
  returning agent_state into v_state;

  if not found then
    raise exception 'patch_chat_state: no chat session for artist % chat %',
      p_artist_id, p_chat_id using errcode = '23503';
  end if;

  return v_state;
end;
$$;


-- -----------------------------------------------------------------------------
-- Projects
-- -----------------------------------------------------------------------------

create or replace function create_project(
  p_artist_id      uuid,
  p_lead_id        uuid,
  p_placement      text default null,
  p_body_zone      text default null,
  p_style          text default null,
  p_first_tattoo   boolean default null,
  p_meaning        text default null,
  p_tattoo_type    text default null,
  p_reference_pics text[] default '{}',
  p_actor          text default 'agent'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row projects;
begin
  if not exists (
    select 1 from leads where id = p_lead_id and artist_id = p_artist_id
  ) then
    raise exception 'create_project: lead % does not belong to artist %',
      p_lead_id, p_artist_id using errcode = '23503';
  end if;

  insert into projects (
    artist_id, lead_id, placement, body_zone, style,
    first_tattoo, meaning, tattoo_type, reference_pics
  ) values (
    p_artist_id, p_lead_id, p_placement, p_body_zone, p_style,
    p_first_tattoo, p_meaning, p_tattoo_type, coalesce(p_reference_pics, '{}')
  )
  returning * into v_row;

  perform log_event(p_artist_id, p_lead_id, v_row.id, p_actor, 'project_created',
    jsonb_build_object('placement', p_placement, 'body_zone', p_body_zone));

  return next v_row;
end;
$$;


create or replace function set_project_stage(
  p_artist_id uuid,
  p_project_id uuid,
  p_stage_key  text,
  p_actor      text default 'agent'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row projects;
begin
  if not exists (select 1 from pipeline_stages where key = p_stage_key) then
    raise exception 'set_project_stage: unknown stage %', p_stage_key using errcode = '23503';
  end if;

  update projects
     set stage_key = p_stage_key,
         closed_at = case
           when (select is_won or is_lost from pipeline_stages where key = p_stage_key)
             then coalesce(closed_at, now())
           else null
         end
   where id = p_project_id and artist_id = p_artist_id
  returning * into v_row;

  if v_row.id is null then
    return;
  end if;

  perform log_event(p_artist_id, v_row.lead_id, v_row.id, p_actor, 'stage_changed',
    jsonb_build_object('stage', p_stage_key));

  return next v_row;
end;
$$;


-- Returns the Lead's current Project, creating one when there is none. A
-- `fechado` (finished) or `perdido` (lost) Project is history: the next
-- conversation about the same person starts a new job.
create or replace function upsert_project(
  p_artist_id uuid,
  p_lead_id   uuid,
  p_actor     text default 'agent'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row projects;
begin
  if not exists (
    select 1 from leads where id = p_lead_id and artist_id = p_artist_id
  ) then
    raise exception 'upsert_project: lead % does not belong to artist %',
      p_lead_id, p_artist_id using errcode = '23503';
  end if;

  -- Two executions of the same conversation must not create two Projects.
  perform pg_advisory_xact_lock(hashtext('project:' || p_lead_id::text)::bigint);

  select * into v_row from projects
   where artist_id = p_artist_id
     and lead_id = p_lead_id
     and stage_key not in ('fechado','perdido')
   order by updated_at desc
   limit 1;

  if found then
    return next v_row;
    return;
  end if;

  insert into projects (artist_id, lead_id, stage_key)
  values (p_artist_id, p_lead_id, 'novo')
  returning * into v_row;

  perform log_event(p_artist_id, p_lead_id, v_row.id, p_actor, 'project_created',
    jsonb_build_object('auto', true));

  return next v_row;
end;
$$;


-- Updates the qualification fields of a Project and, optionally, its stage.
-- Only non-null arguments are applied, so the caller can send the deltas it
-- actually learned this turn. Stage changes go through set_project_stage, which
-- keeps `closed_at` and the audit event consistent.
create or replace function patch_project(
  p_artist_id      uuid,
  p_project_id     uuid,
  p_stage_key      text    default null,
  p_placement      text    default null,
  p_body_zone      text    default null,
  p_style          text    default null,
  p_first_tattoo   boolean default null,
  p_meaning        text    default null,
  p_tattoo_type    text    default null,
  p_reference_pics text[]  default null,
  p_handoff_reason text    default null,
  p_actor          text    default 'agent'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_before  projects;
  v_row     projects;
  v_changed text[] := '{}';
begin
  select * into v_before from projects
   where id = p_project_id and artist_id = p_artist_id
   for update;

  if not found then
    raise exception 'patch_project: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  if p_placement is not null and p_placement is distinct from v_before.placement then
    v_changed := array_append(v_changed, 'placement');
  end if;
  if p_body_zone is not null and p_body_zone is distinct from v_before.body_zone then
    v_changed := array_append(v_changed, 'body_zone');
  end if;
  if p_style is not null and p_style is distinct from v_before.style then
    v_changed := array_append(v_changed, 'style');
  end if;
  if p_first_tattoo is not null and p_first_tattoo is distinct from v_before.first_tattoo then
    v_changed := array_append(v_changed, 'first_tattoo');
  end if;
  if p_meaning is not null and p_meaning is distinct from v_before.meaning then
    v_changed := array_append(v_changed, 'meaning');
  end if;
  if p_tattoo_type is not null and p_tattoo_type is distinct from v_before.tattoo_type then
    v_changed := array_append(v_changed, 'tattoo_type');
  end if;
  if p_handoff_reason is not null and p_handoff_reason is distinct from v_before.handoff_reason then
    v_changed := array_append(v_changed, 'handoff_reason');
  end if;

  update projects set
    placement      = coalesce(p_placement, placement),
    body_zone      = coalesce(p_body_zone, body_zone),
    style          = coalesce(p_style, style),
    first_tattoo   = coalesce(p_first_tattoo, first_tattoo),
    meaning        = coalesce(p_meaning, meaning),
    tattoo_type    = coalesce(p_tattoo_type, tattoo_type),
    reference_pics = coalesce(p_reference_pics, reference_pics),
    handoff_reason = coalesce(p_handoff_reason, handoff_reason)
  where id = p_project_id and artist_id = p_artist_id;

  if array_length(v_changed, 1) is not null then
    perform log_event(p_artist_id, v_before.lead_id, p_project_id, p_actor,
      'project_updated', jsonb_build_object('fields', to_jsonb(v_changed)));
  end if;

  if p_stage_key is not null and p_stage_key is distinct from v_before.stage_key then
    perform set_project_stage(p_artist_id, p_project_id, p_stage_key, p_actor);
  end if;

  select * into v_row from projects
   where id = p_project_id and artist_id = p_artist_id;

  return next v_row;
end;
$$;


-- Everything the agent needs at the start of a turn, in one call: the Lead
-- (created on first contact), the Project being quoted, the chat session used
-- for ownership/scratch state, and the price context for the prompt.
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
  table_price_cents  integer,
  quoted_price_cents integer,
  agent_state        jsonb
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

  select pb.base_price_cents into v_table
    from price_book pb
   where pb.artist_id = p_artist_id
     and pb.placement = v_project.placement
     and pb.body_zone = v_project.body_zone
     and pb.effective_to is null
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
  table_price_cents  := v_table;
  quoted_price_cents := v_quoted;
  agent_state        := coalesce(v_state, '{}'::jsonb);

  return next;
end;
$$;


-- -----------------------------------------------------------------------------
-- Prices and quotes
-- -----------------------------------------------------------------------------

create or replace function lookup_price(
  p_artist_id uuid,
  p_placement text,
  p_body_zone text
)
returns table (
  placement            text,
  body_zone            text,
  base_price_cents     integer,
  session_duration_min integer,
  buffer_min           integer
)
language sql stable security definer
set search_path = public, pg_temp
as $$
  select pb.placement, pb.body_zone, pb.base_price_cents, pb.session_duration_min, pb.buffer_min
  from price_book pb
  where pb.artist_id = p_artist_id
    and pb.placement = p_placement
    and pb.body_zone = p_body_zone
    and pb.effective_to is null
  limit 1;
$$;


create or replace function write_quote(
  p_artist_id           uuid,
  p_project_id          uuid,
  p_base_price_cents    integer,
  p_offered_price_cents integer default null,
  p_discount_percent    numeric default null,
  p_second_cap_cents    integer default null,
  p_valid_until         timestamptz default null,
  p_created_by          text default 'agent'
)
returns setof quotes
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_row     quotes;
  v_offered integer := coalesce(p_offered_price_cents, p_base_price_cents);
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'write_quote: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  -- One live quote per Project: supersede the previous one instead of failing.
  update quotes set status = 'void', accepted_at = null
   where artist_id = p_artist_id and project_id = p_project_id and status = 'open';

  insert into quotes (
    artist_id, project_id, kind, base_price_cents, offered_price_cents,
    discount_percent, second_cap_cents, valid_until, created_by
  ) values (
    p_artist_id, p_project_id, 'standard', p_base_price_cents, v_offered,
    p_discount_percent, p_second_cap_cents, p_valid_until, p_created_by
  )
  returning * into v_row;

  if v_project.stage_key not in ('fechado','perdido') then
    update projects set stage_key = 'orcamento_enviado'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'orcamento_enviado';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_created_by, 'quote_sent',
    jsonb_build_object(
      'quote_id', v_row.id,
      'base_price_cents', p_base_price_cents,
      'offered_price_cents', v_offered
    ));

  return next v_row;
end;
$$;


create or replace function write_promotion_quote(
  p_artist_id   uuid,
  p_project_id  uuid,
  p_promotion_id uuid,
  p_placement   text,
  p_body_zone   text,
  p_created_by  text default 'agent'
)
returns setof quotes
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_promo   promotions;
  v_project projects;
  v_price   promotion_prices;
  v_row     quotes;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'write_promotion_quote: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  select * into v_promo from promotions
   where id = p_promotion_id and artist_id = p_artist_id;
  if not found then
    raise exception 'write_promotion_quote: promotion % does not belong to artist %',
      p_promotion_id, p_artist_id using errcode = '23503';
  end if;

  -- A quote already sent for this promotion is still the valid offer.
  select * into v_row from quotes
   where artist_id = p_artist_id
     and project_id = p_project_id
     and promotion_id = p_promotion_id
     and status in ('open','accepted')
   order by created_at desc
   limit 1;
  if found then
    return next v_row;
    return;
  end if;

  select * into v_price from promotion_prices
   where promotion_id = p_promotion_id
     and placement = p_placement
     and body_zone = p_body_zone;
  if not found then
    return;
  end if;

  update quotes set status = 'void', accepted_at = null
   where artist_id = p_artist_id and project_id = p_project_id and status = 'open';

  insert into quotes (
    artist_id, project_id, kind, promotion_id,
    base_price_cents, offered_price_cents, second_cap_cents, valid_until, created_by
  ) values (
    p_artist_id, p_project_id, 'promotion', p_promotion_id,
    v_price.base_price_cents, v_price.offer_price_cents, v_price.base_price_cents,
    now() + make_interval(days => greatest(v_promo.quote_valid_days, 1)), p_created_by
  )
  returning * into v_row;

  if v_project.stage_key not in ('fechado','perdido') then
    update projects set stage_key = 'orcamento_enviado'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'orcamento_enviado';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_created_by,
    'promotion_quote_sent',
    jsonb_build_object(
      'quote_id', v_row.id,
      'promotion_id', p_promotion_id,
      'promotion', v_promo.name,
      'placement', p_placement,
      'body_zone', p_body_zone,
      'offered_price_cents', v_price.offer_price_cents,
      'second_cap_cents', v_price.base_price_cents,
      'valid_until', v_row.valid_until
    ));

  return next v_row;
end;
$$;


create or replace function accept_quote(
  p_artist_id uuid,
  p_project_id uuid,
  p_quote_id  uuid,
  p_actor     text default 'agent'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row projects;
begin
  if not exists (
    select 1 from quotes
     where id = p_quote_id and artist_id = p_artist_id and project_id = p_project_id
       and status = 'open'
  ) then
    raise exception 'accept_quote: quote % is not open for project %', p_quote_id, p_project_id
      using errcode = '23503';
  end if;

  update quotes set status = 'accepted', accepted_at = now()
   where id = p_quote_id and artist_id = p_artist_id;

  update projects set accepted_quote_id = p_quote_id
   where id = p_project_id and artist_id = p_artist_id
  returning * into v_row;

  perform log_event(p_artist_id, v_row.lead_id, p_project_id, p_actor, 'quote_accepted',
    jsonb_build_object('quote_id', p_quote_id));

  return next v_row;
end;
$$;


-- -----------------------------------------------------------------------------
-- Payments (manual confirmation in v2)
-- -----------------------------------------------------------------------------

create or replace function request_deposit(
  p_artist_id    uuid,
  p_project_id   uuid,
  p_amount_cents integer,
  p_method       text default null,
  p_actor        text default 'agent'
)
returns setof payments
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_row     payments;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'request_deposit: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;
  if p_amount_cents is null or p_amount_cents <= 0 then
    raise exception 'request_deposit: amount must be positive' using errcode = '22023';
  end if;

  select * into v_row from payments
   where artist_id = p_artist_id and project_id = p_project_id
     and kind = 'deposit' and status = 'requested'
   order by requested_at desc
   limit 1;

  if found then
    update payments
       set amount_cents = p_amount_cents, method = coalesce(p_method, method)
     where id = v_row.id
    returning * into v_row;
  else
    insert into payments (artist_id, project_id, kind, amount_cents, method)
    values (p_artist_id, p_project_id, 'deposit', p_amount_cents, p_method)
    returning * into v_row;
  end if;

  if v_project.stage_key not in ('fechado','perdido','agendado') then
    update projects set stage_key = 'aguardando_deposito'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'aguardando_deposito';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_actor, 'deposit_requested',
    jsonb_build_object('payment_id', v_row.id, 'amount_cents', p_amount_cents));

  return next v_row;
end;
$$;


create or replace function confirm_deposit(
  p_artist_id   uuid,
  p_project_id  uuid,
  p_method      text default 'pix',
  p_external_ref text default null,
  p_actor       text default 'artist'
)
returns setof payments
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_lead    leads;
  v_row     payments;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'confirm_deposit: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  select * into v_row from payments
   where artist_id = p_artist_id and project_id = p_project_id
     and kind = 'deposit' and status in ('requested','confirmed')
   order by requested_at desc
   limit 1;
  if not found then
    raise exception 'confirm_deposit: no deposit requested for project %', p_project_id
      using errcode = '23503';
  end if;

  update payments
     set status = 'confirmed',
         confirmed_at = coalesce(confirmed_at, now()),
         method = coalesce(p_method, method),
         external_ref = coalesce(p_external_ref, external_ref)
   where id = v_row.id
  returning * into v_row;

  select * into v_lead from leads where id = v_project.lead_id;

  if v_lead.phone_e164 is not null and v_lead.phone_e164 not like 'anon:%' then
    insert into returning_customers (artist_id, phone_e164, name, source, confirmed_at)
    values (p_artist_id, v_lead.phone_e164, v_lead.name, 'deposit_confirmed', now())
    on conflict (artist_id, phone_e164) do update set
      name         = coalesce(returning_customers.name, excluded.name),
      confirmed_at = excluded.confirmed_at,
      source = case
        when returning_customers.source = 'imported' then returning_customers.source
        else 'deposit_confirmed'
      end;
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_actor, 'deposit_confirmed',
    jsonb_build_object('payment_id', v_row.id, 'amount_cents', v_row.amount_cents));

  return next v_row;
end;
$$;


-- -----------------------------------------------------------------------------
-- Availability and bookings
-- -----------------------------------------------------------------------------

create or replace function check_availability(
  p_artist_id   uuid,
  p_from        timestamptz,
  p_to          timestamptz,
  p_duration_min integer default 60
)
returns table (
  start_at timestamptz,
  end_at   timestamptz
)
language plpgsql stable security definer
set search_path = public, pg_temp
as $$
declare
  v_tz      text;
  v_working jsonb;
  v_day0    date;
  v_day1    date;
  v_dur     interval;
begin
  select coalesce(nullif(a.timezone, ''), 'UTC'), s.working_hours
    into v_tz, v_working
  from artists a
  left join artist_settings s on s.artist_id = a.id
  where a.id = p_artist_id;

  if v_working is null or v_working = '{}'::jsonb then
    return;
  end if;

  v_day0 := (p_from at time zone v_tz)::date;
  v_day1 := (p_to   at time zone v_tz)::date;
  v_dur  := make_interval(mins => greatest(p_duration_min, 1));

  return query
  with days as (
    select generate_series(v_day0::timestamp, v_day1::timestamp, '1 day'::interval)::date as day
  ),
  weeks as (
    select day,
           (array['seg','ter','qua','qui','sex','sab','dom'])[extract(isodow from day)::int] as dow
    from days
  ),
  blocks as (
    select w.day, w.dow, b.value as block
    from weeks w
    cross join lateral jsonb_array_elements_text(v_working -> w.dow) as b(value)
  ),
  spans as (
    select b.day,
           (b.day + split_part(b.block, '-', 1)::time)::timestamp as start_local,
           (b.day + split_part(b.block, '-', 2)::time)::timestamp as end_local
    from blocks b
  ),
  starts as (
    select ((s.start_local + (n || ' hours')::interval) at time zone v_tz) as slot_start,
           (((s.start_local + (n || ' hours')::interval) at time zone v_tz) + v_dur) as slot_end,
           (s.end_local at time zone v_tz) as block_end
    from spans s
    cross join lateral generate_series(
      0,
      greatest(0, ceil(extract(epoch from (s.end_local - s.start_local)) / 3600)::int - 1)
    ) as n
  )
  select st.slot_start, st.slot_end
  from starts st
  where st.slot_start >= p_from
    and st.slot_end <= p_to
    and st.slot_end <= st.block_end
    and not exists (
      select 1 from calendar_entries c
      where c.artist_id = p_artist_id
        and c.active
        and c.start_at < st.slot_end
        and c.end_at > st.slot_start
    )
  order by st.slot_start;
end;
$$;


create or replace function book_slot(
  p_artist_id    uuid,
  p_project_id   uuid,
  p_start_at     timestamptz,
  p_duration_min integer default null,
  p_created_by   text default 'agent'
)
returns setof calendar_entries
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_dur     integer := p_duration_min;
  v_row     calendar_entries;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'book_slot: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  if v_dur is null then
    select pb.session_duration_min into v_dur
    from price_book pb
    where pb.artist_id = p_artist_id
      and pb.placement = v_project.placement
      and pb.body_zone = v_project.body_zone
      and pb.effective_to is null
    limit 1;
  end if;
  v_dur := coalesce(v_dur, 120);

  -- Reduces retries under concurrent booking; the exclusion constraint is the
  -- actual guarantee.
  perform pg_advisory_xact_lock(hashtext(p_artist_id::text)::bigint);

  begin
    insert into calendar_entries (
      artist_id, kind, project_id, lead_id, start_at, end_at, created_by
    ) values (
      p_artist_id, 'booking', p_project_id, v_project.lead_id,
      p_start_at, p_start_at + make_interval(mins => greatest(v_dur, 1)), p_created_by
    )
    returning * into v_row;
  exception when exclusion_violation then
    return;  -- empty set: the slot is taken
  end;

  if v_project.stage_key not in ('fechado','perdido') then
    update projects set stage_key = 'agendado'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'agendado';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_created_by, 'booking_created',
    jsonb_build_object(
      'booking_id', v_row.id,
      'start_at', v_row.start_at,
      'end_at', v_row.end_at
    ));

  return next v_row;
end;
$$;


create or replace function reschedule_booking(
  p_artist_id uuid,
  p_booking_id uuid,
  p_start_at  timestamptz,
  p_actor     text default 'agent'
)
returns setof calendar_entries
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_old calendar_entries;
  v_row calendar_entries;
  v_dur interval;
begin
  select * into v_old from calendar_entries
   where id = p_booking_id and artist_id = p_artist_id
     and kind = 'booking' and active;
  if not found then
    return;
  end if;

  v_dur := v_old.end_at - v_old.start_at;

  begin
    update calendar_entries
       set start_at = p_start_at,
           end_at   = p_start_at + v_dur
     where id = p_booking_id
    returning * into v_row;
  exception when exclusion_violation then
    return;  -- empty set: the new slot is taken; the old booking is untouched
  end;

  perform log_event(p_artist_id, v_row.lead_id, v_row.project_id, p_actor, 'booking_rescheduled',
    jsonb_build_object(
      'booking_id', v_row.id,
      'from', v_old.start_at,
      'to', v_row.start_at
    ));

  return next v_row;
end;
$$;


create or replace function cancel_booking(
  p_artist_id uuid,
  p_booking_id uuid,
  p_reason    text default null,
  p_actor     text default 'agent'
)
returns setof calendar_entries
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row calendar_entries;
begin
  update calendar_entries
     set active = false, reason = coalesce(p_reason, reason)
   where id = p_booking_id and artist_id = p_artist_id
     and kind = 'booking' and active
  returning * into v_row;

  if v_row.id is null then
    return;
  end if;

  perform log_event(p_artist_id, v_row.lead_id, v_row.project_id, p_actor, 'booking_cancelled',
    jsonb_build_object('booking_id', v_row.id, 'reason', p_reason));

  return next v_row;
end;
$$;


create or replace function block_time(
  p_artist_id uuid,
  p_start_at  timestamptz,
  p_end_at    timestamptz,
  p_reason    text default null,
  p_actor     text default 'artist'
)
returns setof calendar_entries
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row calendar_entries;
begin
  if p_end_at <= p_start_at then
    raise exception 'block_time: end must be after start' using errcode = '22023';
  end if;

  perform pg_advisory_xact_lock(hashtext(p_artist_id::text)::bigint);

  begin
    insert into calendar_entries (artist_id, kind, start_at, end_at, reason, created_by)
    values (p_artist_id, 'block', p_start_at, p_end_at, p_reason, p_actor)
    returning * into v_row;
  exception when exclusion_violation then
    return;  -- overlaps a booking or another block
  end;

  perform log_event(p_artist_id, null, null, p_actor, 'time_blocked',
    jsonb_build_object('block_id', v_row.id, 'start_at', v_row.start_at, 'end_at', v_row.end_at));

  return next v_row;
end;
$$;


create or replace function unblock_time(
  p_artist_id uuid,
  p_block_id  uuid
)
returns void
language sql security definer
set search_path = public, pg_temp
as $$
  delete from calendar_entries
   where id = p_block_id and artist_id = p_artist_id and kind = 'block';
$$;


-- -----------------------------------------------------------------------------
-- Promotions
-- -----------------------------------------------------------------------------

create or replace function activate_promotion(
  p_artist_id    uuid,
  p_promotion_id uuid
)
returns setof promotions
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_promo promotions;
begin
  select * into v_promo from promotions
   where id = p_promotion_id and artist_id = p_artist_id;
  if not found or v_promo.status not in ('draft','paused') then
    return;
  end if;

  delete from promotion_prices where promotion_id = p_promotion_id;

  insert into promotion_prices (
    promotion_id, placement, body_zone, base_price_cents, offer_price_cents
  )
  select p_promotion_id, pb.placement, pb.body_zone, pb.base_price_cents,
         ceil(pb.base_price_cents * v_promo.multiplier)::integer
  from price_book pb
  where pb.artist_id = p_artist_id and pb.effective_to is null;

  update promotions
     set status = 'active', activated_at = now(), paused_at = null, ended_at = null
   where id = p_promotion_id
  returning * into v_promo;

  perform log_event(p_artist_id, null, null, 'staff', 'promotion_activated',
    jsonb_build_object('promotion_id', p_promotion_id, 'name', v_promo.name));

  return next v_promo;
end;
$$;


create or replace function pause_promotion(
  p_artist_id    uuid,
  p_promotion_id uuid
)
returns setof promotions
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_promo promotions;
begin
  update promotions
     set status = 'paused', paused_at = now()
   where id = p_promotion_id and artist_id = p_artist_id and status = 'active'
  returning * into v_promo;

  if v_promo.id is null then
    return;
  end if;

  perform log_event(p_artist_id, null, null, 'staff', 'promotion_paused',
    jsonb_build_object('promotion_id', p_promotion_id));

  return next v_promo;
end;
$$;


create or replace function end_promotion(
  p_artist_id    uuid,
  p_promotion_id uuid
)
returns setof promotions
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_promo promotions;
begin
  update promotions
     set status = 'ended', ended_at = now()
   where id = p_promotion_id and artist_id = p_artist_id and status in ('active','paused')
  returning * into v_promo;

  if v_promo.id is null then
    return;
  end if;

  update quotes set status = 'void'
   where artist_id = p_artist_id and promotion_id = p_promotion_id and status = 'open';

  perform log_event(p_artist_id, null, null, 'staff', 'promotion_ended',
    jsonb_build_object('promotion_id', p_promotion_id));

  return next v_promo;
end;
$$;


create or replace function is_returning_customer(
  p_artist_id uuid,
  p_phone     text
)
returns boolean
language sql stable security definer
set search_path = public, pg_temp
as $$
  select exists (
    select 1 from returning_customers
    where artist_id = p_artist_id
      and phone_e164 = normalize_phone(p_phone)
  );
$$;


create or replace function import_returning_customer(
  p_artist_id    uuid,
  p_phone        text,
  p_name         text default null,
  p_confirmed_at timestamptz default now()
)
returns setof returning_customers
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_phone text := normalize_phone(p_phone);
  v_row   returning_customers;
begin
  if v_phone is null then
    raise exception 'import_returning_customer: invalid phone' using errcode = '22023';
  end if;

  insert into returning_customers (artist_id, phone_e164, name, source, confirmed_at)
  values (p_artist_id, v_phone, p_name, 'imported', p_confirmed_at)
  on conflict (artist_id, phone_e164) do update set
    name         = coalesce(excluded.name, returning_customers.name),
    confirmed_at = coalesce(returning_customers.confirmed_at, excluded.confirmed_at)
  returning * into v_row;

  return next v_row;
end;
$$;


create or replace function active_offer_for_project(
  p_artist_id  uuid,
  p_project_id uuid
)
returns table (
  promotion_id             uuid,
  name                     text,
  description              text,
  multiplier               numeric,
  quote_valid_days         integer,
  conditions               jsonb,
  open_quote_id            uuid,
  open_quote_valid_until   timestamptz,
  open_quote_placement     text,
  open_quote_body_zone     text,
  open_quote_offered_cents integer,
  open_quote_second_cap    integer,
  open_quote_valid         boolean
)
language plpgsql stable security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_phone   text;
  v_segment text;
  v_promo   promotions;
  v_quote   quotes;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    return;
  end if;

  select phone_e164 into v_phone from leads where id = v_project.lead_id;

  v_segment := case
    when is_returning_customer(p_artist_id, v_phone) then 'returning'
    else 'new'
  end;

  select * into v_promo from promotions
  where artist_id = p_artist_id
    and status = 'active'
    and (audience = 'all' or audience = v_segment)
  limit 1;

  select * into v_quote from quotes
  where artist_id = p_artist_id
    and project_id = p_project_id
    and status = 'open'
    and (valid_until is null or valid_until > now())
  order by created_at desc
  limit 1;

  if v_promo.id is null and v_quote.id is null then
    return;
  end if;

  promotion_id             := v_promo.id;
  name                     := v_promo.name;
  description              := (select t.description from promotion_types t where t.key = v_promo.type_key);
  multiplier               := v_promo.multiplier;
  quote_valid_days         := v_promo.quote_valid_days;
  conditions               := v_promo.conditions;
  open_quote_id            := v_quote.id;
  open_quote_valid_until   := v_quote.valid_until;
  open_quote_placement     := v_project.placement;
  open_quote_body_zone     := v_project.body_zone;
  open_quote_offered_cents := v_quote.offered_price_cents;
  open_quote_second_cap    := v_quote.second_cap_cents;
  open_quote_valid         := v_quote.id is not null
                              and (v_quote.valid_until is null or v_quote.valid_until > now());

  return next;
end;
$$;


-- -----------------------------------------------------------------------------
-- LGPD erasure
-- -----------------------------------------------------------------------------

-- Removes PII but keeps the Project/Quote/Payment/Event history (design §11).
create or replace function anonymize_lead(
  p_artist_id uuid,
  p_lead_id   uuid,
  p_actor     text default 'staff'
)
returns setof leads
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_lead  leads;
  v_phone text;
begin
  select * into v_lead from leads
   where id = p_lead_id and artist_id = p_artist_id;
  if not found then
    return;
  end if;

  v_phone := v_lead.phone_e164;

  delete from chat_sessions
   where artist_id = p_artist_id and lead_id = p_lead_id;

  delete from lead_channels
   where artist_id = p_artist_id and lead_id = p_lead_id;

  delete from returning_customers
   where artist_id = p_artist_id and phone_e164 = v_phone;

  update leads
     set name        = null,
         phone_raw   = null,
         source      = null,
         phone_e164  = 'anon:' || id::text,
         anonymized_at = now()
   where id = p_lead_id and artist_id = p_artist_id
  returning * into v_lead;

  perform log_event(p_artist_id, p_lead_id, null, p_actor, 'lead_anonymized');

  return next v_lead;
end;
$$;


-- -----------------------------------------------------------------------------
-- Retention helpers (called by a scheduled task)
-- -----------------------------------------------------------------------------

create or replace function purge_chat_memory(p_older_than interval default '30 days')
returns integer
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_deleted integer;
begin
  delete from chat_memory where created_at < now() - p_older_than;
  get diagnostics v_deleted = row_count;
  return v_deleted;
end;
$$;


create or replace function create_event_partitions(p_months_ahead integer default 2)
returns void
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  i integer;
begin
  for i in 0..greatest(p_months_ahead, 0) loop
    perform create_monthly_partition(
      ((now() at time zone 'UTC') + make_interval(months => i))::date
    );
  end loop;
end;
$$;

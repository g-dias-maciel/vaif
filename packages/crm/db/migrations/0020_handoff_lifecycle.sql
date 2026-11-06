-- =============================================================================
-- Handoff lifecycle
--
-- "Aguardando artista" used to be a one-way stage: the agent handed the lead
-- off and the project stayed there forever, even after the artist answered on
-- WhatsApp. The dashboard could only guess from `chat_sessions.owner`, which is
-- blind to *when* the artist replied (an old reply hid a new handoff) and gave
-- the artist no way to resolve or hand the chat back.
--
-- `handoff_at` / `handoff_resolved_at` make the handoff a first-class lifecycle:
--   * entering `aguardando_artista` starts it;
--   * leaving the stage resolves it;
--   * an artist reply (take_chat_control) resolves it and moves the project to
--     `qualificando`, so the dashboard list stays truthful with no clicks.
-- =============================================================================

alter table projects
  add column if not exists handoff_at timestamptz,
  add column if not exists handoff_resolved_at timestamptz;

comment on column projects.handoff_at is
  'When the project entered aguardando_artista (the artist was handed this lead).';
comment on column projects.handoff_resolved_at is
  'When the artist took over (or handed back) the conversation; null while the handoff is open.';

-- Existing handoffs are still open: date them from the last project change.
update projects
   set handoff_at = coalesce(handoff_at, updated_at)
 where stage_key = 'aguardando_artista'
   and handoff_at is null;

create index if not exists projects_open_handoffs
  on projects (artist_id, handoff_at desc)
  where stage_key = 'aguardando_artista' and handoff_resolved_at is null;


-- -----------------------------------------------------------------------------
-- Stage changes start and resolve the handoff
--
-- The SET expressions see the old row values, so `stage_key` below is the stage
-- the project was in before this update.
-- -----------------------------------------------------------------------------
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
         handoff_at = case
           when p_stage_key = 'aguardando_artista'
                and stage_key is distinct from 'aguardando_artista'
             then now()
           else handoff_at
         end,
         handoff_resolved_at = case
           -- A new handoff starts open.
           when p_stage_key = 'aguardando_artista'
                and stage_key is distinct from 'aguardando_artista'
             then null
           -- Leaving the handoff stage means it was handled.
           when p_stage_key is distinct from 'aguardando_artista'
                and stage_key = 'aguardando_artista'
             then now()
           else handoff_resolved_at
         end,
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


-- -----------------------------------------------------------------------------
-- An artist reply resolves an open handoff
--
-- Called by n8n on every message the artist sends from WhatsApp. If the lead
-- has an open handoff, the artist is now handling the conversation: mark it
-- resolved and move the project to `qualificando` (an open, live stage).
-- -----------------------------------------------------------------------------
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
  v_lead_id    uuid := p_lead_id;
  v_project_id uuid;
  v_row        chat_sessions;
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

  -- The artist replying is the takeover: close the open handoff, if any.
  select p.id into v_project_id
    from projects p
   where p.artist_id = p_artist_id
     and p.lead_id = v_lead_id
     and p.stage_key = 'aguardando_artista'
     and p.handoff_resolved_at is null
   order by p.updated_at desc
   limit 1;

  if v_project_id is not null then
    perform set_project_stage(p_artist_id, v_project_id, 'qualificando', 'artist');

    perform log_event(p_artist_id, v_lead_id, v_project_id, 'artist', 'handoff_resolved',
      jsonb_build_object('source', 'whatsapp'));
  end if;

  return next v_row;
end;
$$;


-- -----------------------------------------------------------------------------
-- Resolve a handoff from the CRM ("Assumir" / "Devolver à Beatriz")
-- -----------------------------------------------------------------------------
create or replace function resolve_project_handoff(
  p_artist_id uuid,
  p_project_id uuid,
  p_actor      text default 'artist'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_before projects;
  v_row    projects;
begin
  select * into v_before from projects
   where id = p_project_id and artist_id = p_artist_id
   for update;

  if not found then
    return;
  end if;

  if v_before.stage_key = 'aguardando_artista' then
    perform set_project_stage(p_artist_id, p_project_id, 'qualificando', p_actor);

    perform log_event(p_artist_id, v_before.lead_id, p_project_id, p_actor, 'handoff_resolved',
      jsonb_build_object('source', 'crm'));
  end if;

  select * into v_row from projects
   where id = p_project_id and artist_id = p_artist_id;

  return next v_row;
end;
$$;

revoke all on function resolve_project_handoff(uuid, uuid, text) from public;
grant execute on function resolve_project_handoff(uuid, uuid, text) to crm_app;

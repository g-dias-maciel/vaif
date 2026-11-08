-- =============================================================================
-- Handoff SLA + chat ownership
--
-- Handoffs were a one-shot e-mail. Nothing reminded the artist, nothing
-- escalated, and `chat_sessions.owner` stayed 'agent', so the CRM claimed the
-- bot still owned a conversation a human was supposed to answer.
--
-- This migration adds the reminder/escalation clock and moves chat ownership to
-- the artist the moment a handoff starts. `crm:notify-artist-attention` fills
-- the clock: first e-mail, reminder at 45 min, escalation at 4 h.
-- =============================================================================

alter table projects
  add column if not exists handoff_reminded_at  timestamptz,
  add column if not exists handoff_escalated_at timestamptz;

comment on column projects.handoff_reminded_at is
  'When the 45-minute handoff reminder was sent; null until then.';
comment on column projects.handoff_escalated_at is
  'When the 4-hour handoff escalation was sent; null until then.';

create index if not exists projects_handoff_sla
  on projects (artist_id, handoff_at)
  where stage_key = 'aguardando_artista' and handoff_resolved_at is null;


-- -----------------------------------------------------------------------------
-- set_project_stage — same lifecycle as 0020, plus:
--   * reset the SLA clock when a NEW handoff starts;
--   * hand chat ownership to the artist when entering `aguardando_artista`.
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
  v_row       projects;
  v_old_stage text;
begin
  if not exists (select 1 from pipeline_stages where key = p_stage_key) then
    raise exception 'set_project_stage: unknown stage %', p_stage_key using errcode = '23503';
  end if;

  select stage_key into v_old_stage
  from projects
  where id = p_project_id and artist_id = p_artist_id
  for update;

  if not found then
    return;
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
         handoff_reminded_at = case
           when p_stage_key = 'aguardando_artista'
                and stage_key is distinct from 'aguardando_artista'
             then null
           else handoff_reminded_at
         end,
         handoff_escalated_at = case
           when p_stage_key = 'aguardando_artista'
                and stage_key is distinct from 'aguardando_artista'
             then null
           else handoff_escalated_at
         end,
         closed_at = case
           when (select is_won or is_lost from pipeline_stages where key = p_stage_key)
             then coalesce(closed_at, now())
           else null
         end
   where id = p_project_id and artist_id = p_artist_id
  returning * into v_row;

  -- The artist now owns the conversation. Without this the dashboard keeps
  -- showing "agent" for a chat nobody is answering.
  if p_stage_key = 'aguardando_artista'
     and v_old_stage is distinct from 'aguardando_artista' then
    update chat_sessions
       set owner      = 'artist',
           taken_at   = coalesce(taken_at, now()),
           returned_at = null
     where artist_id = p_artist_id
       and lead_id = v_row.lead_id;
  end if;

  perform log_event(p_artist_id, v_row.lead_id, v_row.id, p_actor, 'stage_changed',
    jsonb_build_object('stage', p_stage_key));

  return next v_row;
end;
$$;

revoke all on function set_project_stage(uuid, uuid, text, text) from public;
grant execute on function set_project_stage(uuid, uuid, text, text) to agent_rw, crm_app;

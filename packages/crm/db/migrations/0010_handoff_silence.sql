-- =============================================================================
-- Silence the SDR agent when a Lead is handed off to the artist
--
-- A handoff means the artist owns the conversation. The workflow sets
-- `leads.agent_blocked_at` when the project enters `aguardando_artista`, so
-- `start_turn` reports `agent_blocked=true` and the next inbound message stops
-- at the Agent Blocked? gate instead of continuing to sell/schedule.
--
-- The artist can reactivate the agent from the CRM (the same `agent_blocked_at`
-- flag the Lead page toggle uses).
-- =============================================================================

create or replace function set_agent_blocked(
  p_artist_id uuid,
  p_lead_id   uuid,
  p_blocked   boolean,
  p_actor     text default 'agent'
)
returns setof leads
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_lead leads;
begin
  update leads
     set agent_blocked_at = case
           when p_blocked then coalesce(agent_blocked_at, now())
           else null
         end
   where id = p_lead_id and artist_id = p_artist_id
  returning * into v_lead;

  if v_lead.id is null then
    return;
  end if;

  perform log_event(p_artist_id, p_lead_id, null, p_actor,
    case when p_blocked then 'agent_blocked' else 'agent_unblocked' end,
    jsonb_build_object('reason', 'handoff'));

  return next v_lead;
end;
$$;

revoke all on function set_agent_blocked(uuid, uuid, boolean, text) from public;
grant execute on function set_agent_blocked(uuid, uuid, boolean, text) to agent_rw, crm_app;

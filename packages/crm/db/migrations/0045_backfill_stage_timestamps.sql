-- =============================================================================
-- Observe mode — Phase 5: historical stage timestamps
--
-- A backfilled booking happened in the past. `set_project_stage` stamps the
-- event and `closed_at` with now(), which would make backfilled closes appear
-- in the current month. This function applies the stage at the historical
-- timestamp, creating the monthly events partition when needed.
--
-- The event actor is an allowed value (`system`); the richer attribution lives
-- in `calendar_entries.created_by`.
-- =============================================================================

create or replace function backfill_project_stage(
  p_artist_id uuid,
  p_project_id uuid,
  p_stage_key text,
  p_at timestamptz,
  p_actor text default 'system'
)
returns void
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_at      timestamptz := coalesce(p_at, now());
begin
  if not exists (select 1 from pipeline_stages where key = p_stage_key) then
    raise exception 'backfill_project_stage: unknown stage %', p_stage_key using errcode = '23503';
  end if;

  select * into v_project
    from projects
   where id = p_project_id and artist_id = p_artist_id
   for update;

  if not found then
    return;
  end if;

  perform create_monthly_partition(v_at::date);

  update projects
     set stage_key = p_stage_key,
         closed_at = case
           when (select is_won or is_lost from pipeline_stages where key = p_stage_key)
             then v_at
           else null
         end,
         handoff_at = case
           when p_stage_key = 'aguardando_artista'
             then coalesce(handoff_at, v_at)
           else handoff_at
         end,
         handoff_resolved_at = case
           when p_stage_key = 'aguardando_artista' then null
           else handoff_resolved_at
         end
   where id = p_project_id and artist_id = p_artist_id;

  insert into events (artist_id, lead_id, project_id, actor, type, payload, created_at)
  values (
    p_artist_id, v_project.lead_id, p_project_id, p_actor,
    'stage_changed', jsonb_build_object('stage', p_stage_key), v_at
  );
end;
$$;

revoke all on function backfill_project_stage(uuid, uuid, text, timestamptz, text) from public;
grant execute on function backfill_project_stage(uuid, uuid, text, timestamptz, text) to crm_app;

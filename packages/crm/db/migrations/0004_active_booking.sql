-- =============================================================================
-- Active booking lookup for the SDR agent
--
-- The agent runs as `agent_rw`, which has no SELECT on calendar_entries. This
-- function exposes only the project's latest active booking and always returns
-- exactly one row (NULLs when there is none) so an n8n Postgres node never
-- emits zero items and stops the workflow.
-- =============================================================================

create or replace function get_active_booking(
  p_artist_id  uuid,
  p_project_id uuid
)
returns table (
  id       uuid,
  start_at timestamptz,
  end_at   timestamptz
)
language sql
stable
security definer
set search_path = public, pg_temp
as $$
  select ce.id, ce.start_at, ce.end_at
  from (select 1) as one
  left join lateral (
    select c.id, c.start_at, c.end_at
    from calendar_entries c
    where c.artist_id = p_artist_id
      and c.project_id = p_project_id
      and c.kind = 'booking'
      and c.active
    order by c.start_at desc
    limit 1
  ) ce on true;
$$;

grant execute on function get_active_booking(uuid, uuid) to agent_rw, crm_app;

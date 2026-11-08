-- =============================================================================
-- Grounding observability — Pass 6
--
-- Before deleting the legacy classifier we need evidence that the grounded
-- path is better on real traffic. This migration records whether a turn was
-- repaired, keeps the original violations, and exposes the two read models the
-- rollout decision uses.
-- =============================================================================

alter table agent_turn_log
  add column if not exists repaired boolean not null default false,
  add column if not exists original_violations jsonb not null default '[]'::jsonb;

comment on column agent_turn_log.repaired is
  'True when the repair-once model produced the sent reply.';
comment on column agent_turn_log.original_violations is
  'Violations found before the repair attempt; kept even when the repair fixed them.';

-- The old 15-argument function is replaced by a 17-argument one.
drop function if exists log_agent_turn(uuid, uuid, uuid, uuid, text, text, text, numeric, jsonb, text, jsonb, jsonb, text, text, integer);

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
  p_latency_ms  integer,
  p_repaired    boolean default false,
  p_original_violations jsonb default '[]'::jsonb
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
    latency_ms, repaired, original_violations
  ) values (
    coalesce(p_turn_id, gen_random_uuid()), p_artist_id, p_lead_id, p_project_id,
    p_chat_id, coalesce(p_mode, 'shadow'), p_intent, p_confidence,
    coalesce(p_facts, '{}'::jsonb), p_reply, coalesce(p_claims, '[]'::jsonb),
    coalesce(p_violations, '[]'::jsonb), p_resolution, p_model, p_latency_ms,
    coalesce(p_repaired, false), coalesce(p_original_violations, '[]'::jsonb)
  )
  returning id into v_id;

  return v_id;
end;
$$;

revoke all on function log_agent_turn(uuid, uuid, uuid, uuid, text, text, text, numeric, jsonb, text, jsonb, jsonb, text, text, integer, boolean, jsonb) from public;
grant execute on function log_agent_turn(uuid, uuid, uuid, uuid, text, text, text, numeric, jsonb, text, jsonb, jsonb, text, text, integer, boolean, jsonb) to agent_rw, crm_app;


-- -----------------------------------------------------------------------------
-- v_agent_grounding_quality — the rollout scoreboard.
-- -----------------------------------------------------------------------------
create or replace view v_agent_grounding_quality as
  select
    (created_at at time zone 'America/Sao_Paulo')::date as day,
    artist_id,
    mode,
    count(*)                                                        as turns,
    count(*) filter (where jsonb_array_length(original_violations) > 0) as turns_with_violations,
    count(*) filter (where repaired)                               as turns_repaired,
    count(*) filter (where resolution like 'canonical_%')          as turns_canonical,
    count(*) filter (where resolution = 'kept_legacy')             as turns_kept_legacy,
    count(*) filter (where resolution = 'repaired')                as turns_repaired_ok,
    count(*) filter (where jsonb_array_length(claims) = 0)         as turns_without_claims,
    round(avg(intent_confidence), 2)                               as avg_confidence
  from agent_turn_log
  group by 1, 2, 3;

grant select on v_agent_grounding_quality to crm_app, analytics_ro;


-- -----------------------------------------------------------------------------
-- v_agent_violation_rules — which rule fires most.
-- -----------------------------------------------------------------------------
create or replace view v_agent_violation_rules as
  select
    (created_at at time zone 'America/Sao_Paulo')::date as day,
    mode,
    v->>'rule' as rule,
    count(*) as occurrences
  from agent_turn_log
  cross join lateral jsonb_array_elements(original_violations) v
  group by 1, 2, 3;

grant select on v_agent_violation_rules to crm_app, analytics_ro;

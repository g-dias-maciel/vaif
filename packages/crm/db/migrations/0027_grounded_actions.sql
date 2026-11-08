-- =============================================================================
-- Grounded actions — Pass 4
--
-- One turn must be applied once, even if n8n retries a node or the execution is
-- resumed after a failure. `agent_turn_actions` is the idempotency key; the
-- wrapper returns the first result for a repeated `turn_id`.
--
-- This migration also does not change `apply_grounded_turn` itself; Pass 4
-- extends it in 0028 with the quote and deposit writes.
-- =============================================================================

create table if not exists agent_turn_actions (
  turn_id    uuid primary key,
  artist_id  uuid references artists(id) on delete cascade,
  result     jsonb,
  created_at timestamptz not null default now()
);

create index if not exists agent_turn_actions_artist
  on agent_turn_actions (artist_id, created_at desc);

comment on table agent_turn_actions is
  'Idempotency ledger for grounded turns. A repeated turn_id returns the stored result instead of applying the writes again.';

grant select, insert, update on agent_turn_actions to agent_rw, crm_app;

create or replace function apply_grounded_turn_once(
  p_artist_id      uuid,
  p_lead_id        uuid,
  p_project_id     uuid,
  p_chat_id        text,
  p_turn_id        uuid,
  p_intent         text,
  p_flags          jsonb,
  p_entities       jsonb,
  p_facts          jsonb,
  p_claims         jsonb,
  p_reply          text,
  p_resolution     text,
  p_offered_slots  jsonb,
  p_lead_text      text,
  p_confidence     numeric
)
returns jsonb
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_turn     uuid := coalesce(p_turn_id, gen_random_uuid());
  v_result   jsonb;
  v_existing jsonb;
begin
  insert into agent_turn_actions (turn_id, artist_id)
  values (v_turn, p_artist_id)
  on conflict (turn_id) do nothing;

  if not found then
    select result into v_existing
    from agent_turn_actions
    where turn_id = v_turn;

    return jsonb_set(
      coalesce(v_existing, '{}'::jsonb),
      '{duplicate}',
      'true'::jsonb,
      true
    );
  end if;

  v_result := apply_grounded_turn(
    p_artist_id, p_lead_id, p_project_id, p_chat_id, v_turn, p_intent,
    p_flags, p_entities, p_facts, p_claims, p_reply, p_resolution,
    p_offered_slots, p_lead_text, p_confidence
  );

  update agent_turn_actions
     set result = v_result
   where turn_id = v_turn;

  return v_result;
end;
$$;

revoke all on function apply_grounded_turn_once(uuid, uuid, uuid, text, uuid, text, jsonb, jsonb, jsonb, jsonb, text, text, jsonb, text, numeric) from public;
grant execute on function apply_grounded_turn_once(uuid, uuid, uuid, text, uuid, text, jsonb, jsonb, jsonb, jsonb, text, text, jsonb, text, numeric) to agent_rw, crm_app;

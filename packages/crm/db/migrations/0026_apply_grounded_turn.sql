-- =============================================================================
-- Grounded turn application — Pass 3
--
-- In enforce mode the legacy `Parse Classification` regex pipeline is bypassed.
-- This function applies the turn from structured inputs only:
--
--   * preflight intent/entities (AI)
--   * facts (SQL)
--   * validated claims (AI + code)
--   * the final reply
--
-- It writes project fields, the pipeline stage, handoff state and the chat
-- scratch state. It never extracts intent from text.
-- =============================================================================

create or replace function apply_grounded_turn(
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
  v_project    projects;
  v_prev       text;
  v_stage      text := null;
  v_reason     text := null;
  v_event      text := null;
  v_pending    text := null;
  v_placement  text;
  v_body_zone  text;
  v_style      text;
  v_first      boolean;
  v_meaning    text;
  v_type       text;
  v_type_pt    text;
  v_has_fields boolean := false;
  v_price      boolean := false;
  v_handoff    boolean := false;
  v_booked     boolean := false;
  v_question   jsonb;
  v_status     text;
  v_phone      text;
  v_deposit    boolean := false;
  v_has_booking boolean := false;
  v_deposit_cents integer := 0;
  v_base       integer;
  v_offered    integer;
  v_quote_written boolean := false;
  v_deposit_requested boolean := false;
begin
  select * into v_project
  from projects
  where id = p_project_id and artist_id = p_artist_id;

  if not found then
    return jsonb_build_object('error', 'project_not_found');
  end if;

  v_prev := v_project.stage_key;

  -- ── Structured claims ──
  select exists (
    select 1 from jsonb_array_elements(coalesce(p_claims, '[]'::jsonb)) c
    where c->>'type' = 'price'
  ) into v_price;

  select exists (
    select 1 from jsonb_array_elements(coalesce(p_claims, '[]'::jsonb)) c
    where c->>'type' = 'handoff'
  ) into v_handoff;

  select exists (
    select 1 from jsonb_array_elements(coalesce(p_claims, '[]'::jsonb)) c
    where c->>'type' = 'deposit'
  ) into v_deposit;

  v_booked := coalesce((p_facts->'booking'->>'created_this_turn')::boolean, false);
  v_has_booking := v_booked
    or coalesce((p_facts->'booking'->>'active')::boolean, false);
  v_status := coalesce(p_facts->'pricing'->>'status', 'no_price_row');
  v_base := nullif(p_facts->'pricing'->>'base_cents', '')::integer;
  v_offered := coalesce(nullif(p_facts->'pricing'->>'offered_cents', '')::integer, v_base);
  v_deposit_cents := coalesce(nullif(p_facts->'pricing'->>'deposit_cents', '')::integer, 0);

  select c into v_question
  from jsonb_array_elements(coalesce(p_claims, '[]'::jsonb)) c
  where c->>'type' = 'question'
  limit 1;

  -- ── Entities (only what the lead said) ──
  v_placement := nullif(btrim(coalesce(p_entities->>'placement', '')), '');
  v_body_zone := nullif(btrim(coalesce(p_entities->>'body_zone', '')), '');
  v_style     := nullif(btrim(coalesce(p_entities->>'style', '')), '');
  v_meaning   := nullif(btrim(coalesce(p_entities->>'meaning', '')), '');

  if (p_entities ? 'first_tattoo') and p_entities->>'first_tattoo' in ('true', 'false') then
    v_first := (p_entities->>'first_tattoo')::boolean;
  end if;

  v_type_pt := lower(coalesce(nullif(p_entities->>'tattoo_type', ''), nullif(p_entities->>'tipo', '')));
  v_type := case v_type_pt
    when 'nova' then 'new'
    when 'cobertura' then 'cover_up'
    when 'reforma' then 'rework'
    else null
  end;

  v_has_fields := (v_placement is not null and v_placement is distinct from v_project.placement)
    or (v_body_zone is not null and v_body_zone is distinct from v_project.body_zone)
    or (v_style is not null and v_style is distinct from v_project.style)
    or (v_first is not null and v_first is distinct from v_project.first_tattoo)
    or (v_meaning is not null and v_meaning is distinct from v_project.meaning)
    or (v_type is not null and v_type is distinct from v_project.tattoo_type);

  -- ── Pending question from the claim, never from a regex ──
  if v_question is not null then
    v_pending := case v_question->>'field'
      when 'type' then 'tipo'
      when 'first_tattoo' then 'primeira_tatuagem'
      when 'photo' then case when coalesce(v_type, v_project.tattoo_type) in ('cover_up', 'rework') then 'foto_nova' else 'foto_atual' end
      when 'doubts' then 'duvidas'
      when 'name' then 'nome'
      when 'placement' then 'placement'
      when 'body_zone' then 'body_zone'
      when 'style' then 'style'
      when 'meaning' then 'meaning'
      else null
    end;
  end if;

  -- ── Stage ──
  if v_handoff
     or p_intent in ('aftercare', 'coverup')
     or coalesce((p_flags->>'needs_human')::boolean, false)
     or coalesce((p_flags->>'wants_artist')::boolean, false) then
    v_stage := 'aguardando_artista';
    v_reason := case
      when p_intent = 'coverup' then 'cover_up'
      when p_intent = 'aftercare' then 'client_service'
      else 'lead_requested_artist'
    end;
    v_event := 'handoff_triggered';
  elsif p_intent = 'ask_price' and v_status <> 'ready' then
    v_stage := 'aguardando_artista';
    v_reason := 'no_price_row';
    v_event := 'handoff_triggered';
  elsif v_booked then
    -- book_slot already moved the project to aguardando_deposito.
    v_stage := null;
    v_event := 'booking_created';
  elsif v_price and v_status <> 'ready' then
    v_stage := 'aguardando_artista';
    v_reason := 'no_price_row';
    v_event := 'handoff_triggered';
  elsif v_price then
    if v_prev in ('novo', 'qualificando') then
      v_stage := 'orcamento_enviado';
    end if;
    v_event := 'quote_sent';
  elsif v_has_fields and v_prev = 'novo' then
    v_stage := 'qualificando';
    v_event := 'stage_changed';
  end if;

  -- ── Lead name stated by the lead, never a profile placeholder ──
  if nullif(btrim(coalesce(p_entities->>'name', '')), '') is not null then
    select l.phone_e164 into v_phone from leads l where l.id = p_lead_id;
    begin
      perform set_lead_name(p_artist_id, v_phone, p_entities->>'name');
    exception when others then
      null;
    end;
  end if;

  -- ── Write project fields first (stage is handled by set_project_stage) ──
  if v_has_fields or v_reason is not null then
    perform patch_project(
      p_artist_id, p_project_id,
      null, v_placement, v_body_zone, v_style, v_first, v_meaning, v_type,
      null, v_reason, 'agent', v_prev
    );
  end if;

  if v_stage is not null then
    perform set_project_stage(p_artist_id, p_project_id, v_stage, 'agent');
  end if;

  if v_stage = 'aguardando_artista' then
    perform set_agent_blocked(p_artist_id, p_lead_id, true, 'agent');
  end if;

  -- ── Quote: facts only, never a model price ──
  if v_price and v_status = 'ready' and v_has_booking is false
     and v_stage is distinct from 'aguardando_artista'
     and v_base is not null
     and coalesce((p_facts->'pricing'->>'open_quote')::boolean, false) is false then
    perform write_quote(p_artist_id, p_project_id, v_base, v_offered);
    v_quote_written := true;
  end if;

  -- ── Deposit: facts amount, only after a booking ──
  if v_deposit and v_has_booking and v_deposit_cents > 0
     and v_stage is distinct from 'aguardando_artista' then
    perform request_deposit(p_artist_id, p_project_id, v_deposit_cents, 'pix');
    v_deposit_requested := true;
    v_event := 'deposit_requested';
  end if;

  -- ── Chat scratch state (offered slots, pending question) ──
  begin
    perform patch_chat_state(p_artist_id, p_chat_id, jsonb_build_object(
      'pending_question', v_pending,
      'offered_slots', coalesce(p_offered_slots, '[]'::jsonb),
      'preflight_intent', p_intent,
      'preflight_confidence', p_confidence,
      'last_agent_message', left(coalesce(p_reply, ''), 600),
      'last_lead_message', left(coalesce(p_lead_text, ''), 400)
    ));
  exception when others then
    -- A missing chat session must not lose the reply.
    null;
  end;

  return jsonb_build_object(
    'stage_key', coalesce(v_stage, v_prev),
    'previous_stage', v_prev,
    'handoff_reason', v_reason,
    'event_type', v_event,
    'pending_question', v_pending,
    'has_price_claim', v_price,
    'has_deposit_claim', v_deposit,
    'quote_written', v_quote_written,
    'deposit_requested', v_deposit_requested,
    'deposit_cents', case when v_deposit_requested then v_deposit_cents else null end,
    'handoff', v_stage = 'aguardando_artista',
    'booked', v_booked,
    'turn_id', p_turn_id,
    'resolution', p_resolution
  );
end;
$$;

revoke all on function apply_grounded_turn(uuid, uuid, uuid, text, uuid, text, jsonb, jsonb, jsonb, jsonb, text, text, jsonb, text, numeric) from public;
grant execute on function apply_grounded_turn(uuid, uuid, uuid, text, uuid, text, jsonb, jsonb, jsonb, jsonb, text, text, jsonb, text, numeric) to agent_rw, crm_app;

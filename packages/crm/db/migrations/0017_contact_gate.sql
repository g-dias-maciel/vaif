-- =============================================================================
-- Saved-contact gate
--
-- When a number that is in the artist's saved WhatsApp contacts messages for
-- the first time, the agent must not answer on its own: it records a pending
-- decision and the artist decides (dashboard + e-mail) whether the agent may
-- answer. `contact_gate` carries that decision per Lead:
--
--   pending  -> agent holds (no automatic reply)
--   allowed  -> agent answers (stays allowed until the artist blocks it)
--   blocked  -> agent stays silent (the number is also written to blocked_numbers)
--
-- `contact_gate_notified_at` de-dupes the attention e-mail.
-- =============================================================================

alter table leads
  add column if not exists contact_gate text
    check (contact_gate in ('pending', 'allowed', 'blocked'));

alter table leads
  add column if not exists contact_gate_notified_at timestamptz;

comment on column leads.contact_gate is
  'Saved-contact decision: pending (agent holds), allowed, or blocked.';

-- `start_turn` gains a column in its return type, so it must be dropped and
-- recreated (CREATE OR REPLACE cannot change a table return type).
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
  contact_gate       text
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
begin
  if v_phone is null then
    raise exception 'start_turn: invalid phone' using errcode = '22023';
  end if;
  if p_chat_id is null or btrim(p_chat_id) = '' then
    raise exception 'start_turn: chat_id is required' using errcode = '22023';
  end if;

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
  contact_gate       := v_lead.contact_gate;
  -- A pending or blocked saved-contact decision holds the agent exactly like
  -- a silenced Lead, so the existing "Agent Blocked?" gate does the work.
  -- coalesce: `NULL in (...)` is NULL, and `false OR NULL` is NULL, which would
  -- make agent_blocked NULL for a normal lead instead of false.
  agent_blocked      := v_lead.agent_blocked_at is not null
    OR coalesce(v_lead.contact_gate in ('pending', 'blocked'), false)
    OR exists (
      select 1
        from artist_settings s
        cross join lateral unnest(s.blocked_numbers) as b
       where s.artist_id = p_artist_id
         and regexp_replace(b, '[^0-9]', '', 'g')
             = regexp_replace(v_phone, '[^0-9]', '', 'g')
    );

  return next;
end;
$$;

revoke all on function start_turn(uuid, text, text, text, text) from public;
grant execute on function start_turn(uuid, text, text, text, text) to agent_rw, crm_app;

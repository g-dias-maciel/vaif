-- =============================================================================
-- Deposit stage transitions
--
-- book_slot() now parks the project in `aguardando_deposito` (dashboard:
-- "Aguardando sinal") instead of `agendado`. confirm_deposit() moves it to
-- `agendado` only after the sinal is actually confirmed.
-- =============================================================================

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

  -- A slot is only "agendado" after the sinal is confirmed. Until then the
  -- project waits in aguardando_deposito so the dashboard shows "Aguardando sinal".
  if v_project.stage_key not in ('fechado','perdido','agendado','aguardando_deposito') then
    update projects set stage_key = 'aguardando_deposito'
     where id = p_project_id and artist_id = p_artist_id;
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

  -- Confirming the sinal moves the project out of "Aguardando sinal".
  if v_project.stage_key not in ('fechado','perdido') then
    update projects set stage_key = 'agendado'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'agendado';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_actor, 'deposit_confirmed',
    jsonb_build_object('payment_id', v_row.id, 'amount_cents', v_row.amount_cents));

  return next v_row;
end;
$$;

-- Existing projects that are already waiting for a requested sinal were left
-- in `agendado` by the old book_slot(). Move them back to the correct stage.
update projects p
   set stage_key = 'aguardando_deposito'
 where p.stage_key = 'agendado'
   and exists (
     select 1 from payments pay
      where pay.artist_id = p.artist_id
        and pay.project_id = p.id
        and pay.kind = 'deposit'
        and pay.status = 'requested'
   );

grant execute on function book_slot(uuid, uuid, timestamptz, integer, text) to agent_rw, crm_app;
grant execute on function confirm_deposit(uuid, uuid, text, text, text) to agent_rw, crm_app;

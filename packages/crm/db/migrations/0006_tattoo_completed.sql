-- =============================================================================
-- Returning customer only after the tattoo is finished
--
-- confirm_deposit() no longer marks the lead as returning; complete_project()
-- does it when the artist confirms the tattoo was made. Existing deposit-only
-- returning rows without a finished project are removed.
-- =============================================================================

alter table returning_customers
  drop constraint if exists returning_customers_source_check;

alter table returning_customers
  add constraint returning_customers_source_check
  check (source in ('imported', 'deposit_confirmed', 'tattoo_completed'));

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

create or replace function complete_project(
  p_artist_id  uuid,
  p_project_id uuid,
  p_actor      text default 'artist'
)
returns setof projects
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_lead    leads;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'complete_project: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  if v_project.stage_key = 'perdido' then
    raise exception 'complete_project: a lost project cannot be completed'
      using errcode = '22023';
  end if;

  update projects
     set stage_key = 'fechado',
         closed_at = coalesce(closed_at, now())
   where id = p_project_id and artist_id = p_artist_id
  returning * into v_project;

  select * into v_lead from leads where id = v_project.lead_id;

  -- A client becomes "recorrente" only after a tattoo is actually finished.
  if v_lead.phone_e164 is not null and v_lead.phone_e164 not like 'anon:%' then
    insert into returning_customers (artist_id, phone_e164, name, source, confirmed_at)
    values (p_artist_id, v_lead.phone_e164, v_lead.name, 'tattoo_completed', now())
    on conflict (artist_id, phone_e164) do update set
      name         = coalesce(returning_customers.name, excluded.name),
      confirmed_at = excluded.confirmed_at,
      source = case
        when returning_customers.source = 'imported' then returning_customers.source
        else 'tattoo_completed'
      end;
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_actor, 'tattoo_completed',
    jsonb_build_object('project_id', p_project_id));

  return next v_project;
end;
$$;


-- Remove rows created by the old "deposit = returning" rule for leads with no
-- finished project. Keep imported clients and completed projects.
delete from returning_customers rc
 where rc.source = 'deposit_confirmed'
   and not exists (
     select 1
       from leads l
       join projects p on p.lead_id = l.id
      where l.artist_id = rc.artist_id
        and l.phone_e164 = rc.phone_e164
        and p.stage_key = 'fechado'
   );

update returning_customers rc
   set source = 'tattoo_completed'
 where rc.source = 'deposit_confirmed'
   and exists (
     select 1
       from leads l
       join projects p on p.lead_id = l.id
      where l.artist_id = rc.artist_id
        and l.phone_e164 = rc.phone_e164
        and p.stage_key = 'fechado'
   );

grant execute on function confirm_deposit(uuid, uuid, text, text, text) to agent_rw, crm_app;
grant execute on function complete_project(uuid, uuid, text) to crm_app;

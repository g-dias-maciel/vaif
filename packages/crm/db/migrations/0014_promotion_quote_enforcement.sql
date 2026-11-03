-- =============================================================================
-- Promotion price enforcement + booking-readiness RPC
--
-- 1. `write_quote` is the only way a quote enters the CRM (the AI tool and the
--    deterministic Ensure Quote node both call it). The model routinely quotes
--    the TABLE price and then mentions the promotion, which used to store a
--    standard quote and mark the project as "price liberated". When an active
--    promotion matches the project's placement/zone, the promotion price now
--    wins. A negotiated discount (offered below the table) is left alone.
--
-- 2. Quotes are refused until the project has placement + body_zone. Quoting a
--    project with no zone left it flagged as quoted without the lead ever
--    seeing a number, and the promotion was never applied afterwards.
--
-- 3. `project_booking_ready` lets the agent check the booking gate through the
--    RPC surface: `agent_rw` deliberately has no direct access to domain
--    tables, so the inline `select 1 from projects` in the Check Availability
--    node failed with "permission denied for table projects".
-- =============================================================================

create or replace function write_quote(
  p_artist_id           uuid,
  p_project_id          uuid,
  p_base_price_cents    integer,
  p_offered_price_cents integer default null,
  p_discount_percent    numeric default null,
  p_second_cap_cents    integer default null,
  p_valid_until         timestamptz default null,
  p_created_by          text default 'agent'
)
returns setof quotes
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_row     quotes;
  v_offered integer := coalesce(p_offered_price_cents, p_base_price_cents);
  v_promo   promotions;
  v_phone   text;
  v_segment text;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'write_quote: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  -- No placement/zone yet means there is no price to quote. Returning nothing
  -- keeps the price gate closed instead of marking the project as quoted.
  if v_project.placement is null or v_project.body_zone is null then
    return;
  end if;

  -- Never raise a price the lead already received, and never replace the live
  -- quote with a more expensive one (the promotion price is higher than the
  -- table price, so an honoured quote at the table price must survive).
  select * into v_row from quotes
   where artist_id = p_artist_id
     and project_id = p_project_id
     and status = 'open'
     and (valid_until is null or valid_until > now())
   order by created_at desc
   limit 1;
  if found and v_row.offered_price_cents <= v_offered then
    return next v_row;
    return;
  end if;

  select phone_e164 into v_phone from leads where id = v_project.lead_id;
  v_segment := case
    when is_returning_customer(p_artist_id, v_phone) then 'returning'
    else 'new'
  end;

  select * into v_promo from promotions
   where artist_id = p_artist_id
     and status = 'active'
     and (audience = 'all' or audience = v_segment)
   limit 1;

  -- The promotion price is authoritative: the model quotes the table price and
  -- adds the promotion in the same message, so a standard quote at the table
  -- price would silently undercharge the pair.
  if v_promo.id is not null and v_offered = p_base_price_cents then
    select * into v_row from write_promotion_quote(
      p_artist_id, p_project_id, v_promo.id,
      v_project.placement, v_project.body_zone, p_created_by
    ) limit 1;
    if found then
      return next v_row;
      return;
    end if;
  end if;

  -- Re-sending the same price (the agent often confirms a quote it already
  -- wrote this turn) must not supersede and recreate the row.
  select * into v_row from quotes
   where artist_id = p_artist_id
     and project_id = p_project_id
     and status = 'open'
     and base_price_cents = p_base_price_cents
     and offered_price_cents = v_offered
   order by created_at desc
   limit 1;
  if found then
    return next v_row;
    return;
  end if;

  -- One live quote per Project: supersede the previous one instead of failing.
  update quotes set status = 'void', accepted_at = null
   where artist_id = p_artist_id and project_id = p_project_id and status = 'open';

  insert into quotes (
    artist_id, project_id, kind, base_price_cents, offered_price_cents,
    discount_percent, second_cap_cents, valid_until, created_by
  ) values (
    p_artist_id, p_project_id, 'standard', p_base_price_cents, v_offered,
    p_discount_percent, p_second_cap_cents, p_valid_until, p_created_by
  )
  returning * into v_row;

  if v_project.stage_key not in ('fechado','perdido') then
    update projects set stage_key = 'orcamento_enviado'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'orcamento_enviado';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_created_by, 'quote_sent',
    jsonb_build_object(
      'quote_id', v_row.id,
      'base_price_cents', p_base_price_cents,
      'offered_price_cents', v_offered
    ));

  return next v_row;
end;
$$;


-- The agent's booking gate: type, placement and zone must all be known before
-- Beatriz offers or reserves a slot.
create or replace function project_booking_ready(
  p_project_id uuid,
  p_artist_id  uuid
)
returns boolean
language sql stable security definer
set search_path = public, pg_temp
as $$
  select exists (
    select 1 from projects p
     where p.id = p_project_id
       and p.artist_id = p_artist_id
       and p.tattoo_type is not null
       and p.placement is not null
       and p.body_zone is not null
  );
$$;


revoke all on function write_quote(uuid, uuid, integer, integer, numeric, integer, timestamptz, text) from public;
grant execute on function write_quote(uuid, uuid, integer, integer, numeric, integer, timestamptz, text) to agent_rw, crm_app;

revoke all on function project_booking_ready(uuid, uuid) from public;
grant execute on function project_booking_ready(uuid, uuid) to agent_rw, crm_app;

-- =============================================================================
-- The promotion's second tattoo has no price
--
-- "Leve 2" is a same-size freebie combined directly with the artist: the agent
-- never quotes a value for it. The quote keeps the promotion kind and the
-- multiplier price, but stores no second cap.
-- =============================================================================

create or replace function write_promotion_quote(
  p_artist_id   uuid,
  p_project_id  uuid,
  p_promotion_id uuid,
  p_placement   text,
  p_body_zone   text,
  p_created_by  text default 'agent'
)
returns setof quotes
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_promo   promotions;
  v_project projects;
  v_price   promotion_prices;
  v_row     quotes;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'write_promotion_quote: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;

  select * into v_promo from promotions
   where id = p_promotion_id and artist_id = p_artist_id;
  if not found then
    raise exception 'write_promotion_quote: promotion % does not belong to artist %',
      p_promotion_id, p_artist_id using errcode = '23503';
  end if;

  -- A quote already sent for this promotion is still the valid offer.
  select * into v_row from quotes
   where artist_id = p_artist_id
     and project_id = p_project_id
     and promotion_id = p_promotion_id
     and status in ('open','accepted')
   order by created_at desc
   limit 1;
  if found then
    return next v_row;
    return;
  end if;

  select * into v_price from promotion_prices
   where promotion_id = p_promotion_id
     and placement = p_placement
     and body_zone = p_body_zone;
  if not found then
    return;
  end if;

  update quotes set status = 'void', accepted_at = null
   where artist_id = p_artist_id and project_id = p_project_id and status = 'open';

  insert into quotes (
    artist_id, project_id, kind, promotion_id,
    base_price_cents, offered_price_cents, second_cap_cents, valid_until, created_by
  ) values (
    p_artist_id, p_project_id, 'promotion', p_promotion_id,
    v_price.base_price_cents, v_price.offer_price_cents, null,
    now() + make_interval(days => greatest(v_promo.quote_valid_days, 1)), p_created_by
  )
  returning * into v_row;

  if v_project.stage_key not in ('fechado','perdido') then
    update projects set stage_key = 'orcamento_enviado'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'orcamento_enviado';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_created_by,
    'promotion_quote_sent',
    jsonb_build_object(
      'quote_id', v_row.id,
      'promotion_id', p_promotion_id,
      'promotion', v_promo.name,
      'placement', p_placement,
      'body_zone', p_body_zone,
      'offered_price_cents', v_price.offer_price_cents,
      'second_cap_cents', null,
      'valid_until', v_row.valid_until
    ));

  return next v_row;
end;
$$;

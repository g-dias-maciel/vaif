-- =============================================================================
-- Grounded conditions — Pass 5
--
-- The agent may only state the conditions the artist actually configured.
-- These columns are the source for `build_turn_facts().conditions` and the
-- prompt facts block; the validator rejects any other policy/promise.
-- =============================================================================

alter table artist_settings
  add column if not exists reschedule_policy_hours integer,
  add column if not exists cancellation_policy_hours integer,
  add column if not exists payment_methods text[] not null default '{}',
  add column if not exists allow_hold_slot boolean not null default false,
  add column if not exists guarantee_price boolean not null default false;

comment on column artist_settings.reschedule_policy_hours is
  'Minimum notice, in hours, for a reschedule. Null = not configured; the agent must not state a policy.';
comment on column artist_settings.cancellation_policy_hours is
  'Minimum notice, in hours, for a cancellation. Null = not configured.';
comment on column artist_settings.payment_methods is
  'Allowed payment methods: pix, credit_card, debit_card, cash, installments. Empty = only PIX if configured.';
comment on column artist_settings.allow_hold_slot is
  'Whether the agent may promise to hold a slot without the deposit.';
comment on column artist_settings.guarantee_price is
  'Whether the agent may promise a price guarantee beyond the open quote.';

-- Recreate the facts function with the condition fields.
create or replace function build_turn_facts(
  p_artist_id uuid,
  p_project_id uuid,
  p_phone     text,
  p_intent    text default null,
  p_entities  jsonb default '{}'::jsonb
)
returns jsonb
language plpgsql stable security definer
set search_path = public, pg_temp
as $$
declare
  v_project     projects;
  v_phone       text := normalize_phone(p_phone);
  v_base        integer;
  v_open_quote  integer;
  v_promo       jsonb;
  v_dep_type    text;
  v_dep_value   integer;
  v_pix         text;
  v_city        text;
  v_address     text;
  v_hours       jsonb;
  v_resched     integer;
  v_cancel      integer;
  v_methods     text[];
  v_hold        boolean;
  v_guarantee   boolean;
  v_deposit     integer;
  v_day         text := nullif(btrim(coalesce(p_entities->>'requested_day', '')), '');
  v_window      text := nullif(btrim(coalesce(p_entities->>'requested_window', '')), '');
  v_av_status   text;
  v_av_missing  text;
  v_slots       jsonb;
  v_booking_id  uuid;
  v_booking_at  timestamptz;
  v_created     boolean := false;
  v_returning   boolean := false;
  v_lead_name   text;
  v_timezone    text;
  v_placement   text;
  v_body_zone   text;
  v_style       text;
  v_type        text;
begin
  select * into v_project
  from projects
  where id = p_project_id and artist_id = p_artist_id;

  if not found then
    return jsonb_build_object('error', 'project_not_found');
  end if;

  v_placement := coalesce(nullif(btrim(coalesce(p_entities->>'placement', '')), ''), v_project.placement);
  v_body_zone := coalesce(nullif(btrim(coalesce(p_entities->>'body_zone', '')), ''), v_project.body_zone);
  v_style     := coalesce(nullif(btrim(coalesce(p_entities->>'style', '')), ''), v_project.style);
  v_type      := case lower(coalesce(nullif(p_entities->>'tattoo_type', ''), nullif(p_entities->>'tipo', '')))
    when 'nova' then 'new'
    when 'cobertura' then 'cover_up'
    when 'reforma' then 'rework'
    else v_project.tattoo_type
  end;

  select pb.base_price_cents into v_base
  from price_book pb
  where pb.artist_id = p_artist_id
    and pb.placement = v_placement
    and pb.body_zone = v_body_zone
    and pb.effective_to is null
  order by pb.effective_from desc
  limit 1;

  select q.offered_price_cents into v_open_quote
  from quotes q
  where q.artist_id = p_artist_id
    and q.project_id = p_project_id
    and q.status = 'open'
    and (q.valid_until is null or q.valid_until > now())
  order by q.created_at desc
  limit 1;

  select jsonb_build_object(
           'name', p.name,
           'multiplier', p.multiplier,
           'price_cents', pp.base_price_cents
         )
    into v_promo
  from promotions p
  left join promotion_prices pp
    on pp.promotion_id = p.id
   and pp.placement = v_placement
   and pp.body_zone = v_body_zone
  where p.artist_id = p_artist_id
    and p.status = 'active'
  limit 1;

  select s.deposit_type, s.deposit_value, s.pix_key, s.city, s.address, s.working_hours,
         s.reschedule_policy_hours, s.cancellation_policy_hours, s.payment_methods,
         s.allow_hold_slot, s.guarantee_price
    into v_dep_type, v_dep_value, v_pix, v_city, v_address, v_hours,
         v_resched, v_cancel, v_methods, v_hold, v_guarantee
  from artist_settings s
  where s.artist_id = p_artist_id;

  v_dep_type := coalesce(v_dep_type, 'percent');
  v_dep_value := coalesce(v_dep_value, 0);
  v_methods := coalesce(v_methods, '{}');

  v_deposit := case
    when v_dep_type = 'fixed' then round(v_dep_value * 100)::integer
    else ceil(coalesce(v_base, v_open_quote, 0) * v_dep_value / 100.0)::integer
  end;

  select
    case when bool_or(o.start_at is not null) then 'ready' else max(o.status) end,
    max(o.missing),
    coalesce(
      jsonb_agg(
        jsonb_build_object('id', 'F' || o.rn, 'start_at', o.start_at, 'label', o.label)
        order by o.start_at
      ) filter (where o.start_at is not null),
      '[]'::jsonb
    )
    into v_av_status, v_av_missing, v_slots
  from (
    select b.*,
           row_number() over (order by b.start_at) as rn
    from booking_options_v2(
      p_artist_id, p_project_id, now(), now() + interval '60 days',
      120, v_day, v_window
    ) b
  ) o;

  select c.id, c.start_at
    into v_booking_id, v_booking_at
  from calendar_entries c
  join leads l on l.id = c.lead_id and l.artist_id = c.artist_id
  where c.artist_id = p_artist_id
    and c.kind = 'booking'
    and c.active
    and l.phone_e164 = v_phone
  order by c.start_at desc
  limit 1;

  select exists (
    select 1 from calendar_entries c
    where c.artist_id = p_artist_id
      and c.project_id = p_project_id
      and c.kind = 'booking'
      and c.active
      and (c.created_at >= now() - interval '3 minutes'
           or c.updated_at >= now() - interval '3 minutes')
  ) into v_created;

  select l.name into v_lead_name
  from leads l
  where l.id = v_project.lead_id;

  select a.timezone into v_timezone
  from artists a
  where a.id = p_artist_id;

  select exists (
    select 1 from returning_customers rc
    where rc.artist_id = p_artist_id and rc.phone_e164 = v_phone
  ) into v_returning;

  return jsonb_build_object(
    'turn', jsonb_build_object(
      'intent', p_intent,
      'entities', coalesce(p_entities, '{}'::jsonb)
    ),
    'availability', jsonb_build_object(
      'status', coalesce(v_av_status, 'no_slots'),
      'missing', v_av_missing,
      'slots', coalesce(v_slots, '[]'::jsonb)
    ),
    'pricing', jsonb_build_object(
      'status', case
        when v_base is null and v_open_quote is null and v_promo is null then 'no_price_row'
        else 'ready'
      end,
      'base_cents', v_base,
      'offered_cents', coalesce(v_open_quote, v_base),
      'deposit_cents', v_deposit,
      'open_quote', (v_open_quote is not null),
      'promotion', v_promo
    ),
    'conditions', jsonb_build_object(
      'deposit_type', v_dep_type,
      'deposit_value', v_dep_value,
      'pix_available', (v_pix is not null and btrim(v_pix) <> ''),
      'reschedule_hours', v_resched,
      'cancellation_hours', v_cancel,
      'payment_methods', to_jsonb(v_methods),
      'allow_hold_slot', v_hold,
      'guarantee_price', v_guarantee
    ),
    'booking', jsonb_build_object(
      'active', v_booking_id is not null,
      'start_at', v_booking_at,
      'created_this_turn', v_created
    ),
    'project', jsonb_build_object(
      'placement', v_placement,
      'body_zone', v_body_zone,
      'style', v_style,
      'tattoo_type', v_type,
      'first_tattoo', v_project.first_tattoo,
      'meaning', v_project.meaning
    ),
    'client', jsonb_build_object(
      'name', v_lead_name,
      'returning', v_returning,
      'aftercare', (p_intent = 'aftercare')
    ),
    'studio', jsonb_build_object(
      'city', v_city,
      'address', v_address,
      'timezone', coalesce(v_timezone, 'America/Sao_Paulo'),
      'working_hours', coalesce(v_hours, '{}'::jsonb)
    )
  );
end;
$$;

revoke all on function build_turn_facts(uuid, uuid, text, text, jsonb) from public;
grant execute on function build_turn_facts(uuid, uuid, text, text, jsonb) to agent_rw, crm_app;

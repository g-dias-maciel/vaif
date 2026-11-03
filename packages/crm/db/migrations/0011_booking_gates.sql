-- =============================================================================
-- Booking/deposit gates + time-window availability
-- =============================================================================

create or replace function request_deposit(
  p_artist_id    uuid,
  p_project_id   uuid,
  p_amount_cents integer,
  p_method       text default null,
  p_actor        text default 'agent'
)
returns setof payments
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_project projects;
  v_row     payments;
begin
  select * into v_project from projects
   where id = p_project_id and artist_id = p_artist_id;
  if not found then
    raise exception 'request_deposit: project % does not belong to artist %',
      p_project_id, p_artist_id using errcode = '23503';
  end if;
  if p_amount_cents is null or p_amount_cents <= 0 then
    raise exception 'request_deposit: amount must be positive' using errcode = '22023';
  end if;

  -- The signal only makes sense after the desired slot is reserved. The agent
  -- must call book_slot first; this also protects against a model that jumps
  -- straight to the deposit.
  if not exists (
    select 1 from calendar_entries c
     where c.artist_id = p_artist_id
       and c.project_id = p_project_id
       and c.kind = 'booking'
       and c.active
  ) then
    return;  -- no slot yet: the agent must book first, not error
  end if;

  select * into v_row from payments
   where artist_id = p_artist_id and project_id = p_project_id
     and kind = 'deposit' and status = 'requested'
   order by requested_at desc
   limit 1;

  if found then
    update payments
       set amount_cents = p_amount_cents, method = coalesce(p_method, method)
     where id = v_row.id
    returning * into v_row;
  else
    insert into payments (artist_id, project_id, kind, amount_cents, method)
    values (p_artist_id, p_project_id, 'deposit', p_amount_cents, p_method)
    returning * into v_row;
  end if;

  if v_project.stage_key not in ('fechado','perdido','agendado') then
    update projects set stage_key = 'aguardando_deposito'
     where id = p_project_id and artist_id = p_artist_id
       and stage_key <> 'aguardando_deposito';
  end if;

  perform log_event(p_artist_id, v_project.lead_id, p_project_id, p_actor, 'deposit_requested',
    jsonb_build_object('payment_id', v_row.id, 'amount_cents', p_amount_cents));

  return next v_row;
end;
$$;


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

  -- Never book a tattoo slot before the type/placement/zone are known. A
  -- studio visit is not a tattoo booking; the agent must finish qualification.
  if v_project.tattoo_type is null
     or v_project.placement is null
     or v_project.body_zone is null then
    return;  -- empty set: not qualified yet
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


-- =============================================================================
-- booking_options + time window
--
-- The old 5-argument version only understood a day. Leads often say "depois
-- das 14h" / "de manhã" / "quarta às 16"; this version parses a time window
-- and filters the slots, so the agent can never offer or book a morning slot
-- to someone who said they work mornings.
-- =============================================================================
create or replace function booking_options(
  p_artist_id    uuid,
  p_from         timestamptz,
  p_to           timestamptz,
  p_duration_min integer default 120,
  p_day_text     text default null,
  p_time_text    text default null
)
returns table (
  start_at timestamptz,
  end_at   timestamptz,
  label    text
)
language plpgsql stable security definer
set search_path = public, pg_temp
as $$
declare
  v_tz        text;
  v_from      timestamptz;
  v_today     date;
  v_text      text;
  v_time_text text;
  v_day       date;
  v_dow       integer;
  v_date_text text;
  v_after     time;
  v_before    time;
  v_match     text[];
  v_hour      integer;
  v_minute    integer;
begin
  select coalesce(nullif(a.timezone, ''), 'UTC')
    into v_tz
  from artists a
  where a.id = p_artist_id;

  if v_tz is null then
    return;
  end if;

  v_today := (now() at time zone v_tz)::date;
  v_text := lower(translate(coalesce(p_day_text, ''), 'áàâãéêíóôõúüç', 'aaaaeeiooouuc'));
  v_time_text := lower(translate(coalesce(p_time_text, ''), 'áàâãéêíóôõúüç', 'aaaaeeiooouuc'));

  -- Word numbers: "depois das duas" -> "depois das 2".
  v_time_text := regexp_replace(v_time_text, '\muma\M', '1', 'g');
  v_time_text := regexp_replace(v_time_text, '\mduas\M', '2', 'g');
  v_time_text := regexp_replace(v_time_text, '\mtres\M', '3', 'g');
  v_time_text := regexp_replace(v_time_text, '\mquatro\M', '4', 'g');
  v_time_text := regexp_replace(v_time_text, '\mcinco\M', '5', 'g');
  v_time_text := regexp_replace(v_time_text, '\mseis\M', '6', 'g');
  v_time_text := regexp_replace(v_time_text, '\msete\M', '7', 'g');
  v_time_text := regexp_replace(v_time_text, '\moito\M', '8', 'g');
  v_time_text := regexp_replace(v_time_text, '\mnove\M', '9', 'g');
  v_time_text := regexp_replace(v_time_text, '\mdez\M', '10', 'g');
  v_time_text := regexp_replace(v_time_text, '\monze\M', '11', 'g');
  v_time_text := regexp_replace(v_time_text, '\mdoze\M', '12', 'g');

  if v_time_text ~ '(depois das|apos as|a partir das|a partir de|das|as)\s*[0-9]{1,2}(:[0-9]{2})?' then
    v_match := regexp_match(v_time_text, '(?:depois das|apos as|a partir das|a partir de|das|as)\s*([0-9]{1,2})(?::([0-9]{2}))?');
    v_hour := v_match[1]::int;
    v_minute := coalesce(v_match[2]::int, 0);
    -- "depois das duas" is 14h; a digit hour (14) is already 24h.
    if v_hour between 1 and 11 and v_time_text ~ '(depois das|apos as|a partir das|a partir de)' then
      v_hour := v_hour + 12;
    end if;
    v_after := make_time(v_hour, v_minute, 0);
  elsif v_time_text ~ '(antes das|antes de)\s*[0-9]{1,2}(:[0-9]{2})?' then
    v_match := regexp_match(v_time_text, '(?:antes das|antes de)\s*([0-9]{1,2})(?::([0-9]{2}))?');
    v_hour := v_match[1]::int;
    v_minute := coalesce(v_match[2]::int, 0);
    v_before := make_time(v_hour, v_minute, 0);
  elsif v_time_text ~ '^\s*[0-9]{1,2}(:[0-9]{2})?\s*h?\s*$' then
    v_match := regexp_match(v_time_text, '^\s*([0-9]{1,2})(?::([0-9]{2}))?\s*h?\s*$');
    v_hour := v_match[1]::int;
    v_minute := coalesce(v_match[2]::int, 0);
    v_after := make_time(v_hour, v_minute, 0);
    v_before := make_time(least(v_hour + 1, 23), v_minute, 0);
  elsif v_time_text ~ 'manha' then
    v_before := time '12:00';
  elsif v_time_text ~ 'tarde' then
    v_after := time '12:00';
    v_before := time '18:00';
  elsif v_time_text ~ 'noite' then
    v_after := time '18:00';
  elsif v_time_text ~ 'meio dia|meio-dia' then
    v_after := time '12:00';
  end if;

  -- "minha segunda tatuagem" is not Monday.
  v_text := regexp_replace(v_text, 'segunda\s+(tatuagem|tattoo|vez)', '', 'g');

  if btrim(v_text) <> '' then
    if position('amanha' in v_text) > 0 then
      v_day := v_today + 1;
    elsif position('domingo' in v_text) > 0 then
      v_dow := 7;
    elsif position('segunda' in v_text) > 0 then
      v_dow := 1;
    elsif position('terca' in v_text) > 0 then
      v_dow := 2;
    elsif position('quarta' in v_text) > 0 then
      v_dow := 3;
    elsif position('quinta' in v_text) > 0 then
      v_dow := 4;
    elsif position('sexta' in v_text) > 0 then
      v_dow := 5;
    elsif position('sabado' in v_text) > 0 then
      v_dow := 6;
    elsif v_text ~ '\d{4}-\d{2}-\d{2}' then
      v_date_text := substring(v_text from '(\d{4}-\d{2}-\d{2})');
      v_day := v_date_text::date;
    elsif v_text ~ '\d{1,2}/\d{1,2}(/\d{2,4})?' then
      v_date_text := substring(v_text from '(\d{1,2}/\d{1,2}(?:/\d{2,4})?)');
      if v_date_text ~ '^\d{1,2}/\d{1,2}/\d{4}$' then
        v_day := to_date(v_date_text, 'DD/MM/YYYY');
      else
        v_day := to_date(v_date_text || '/' || extract(year from v_today)::int, 'DD/MM/YYYY');
        if v_day < v_today then
          v_day := (v_day + interval '1 year')::date;
        end if;
      end if;
    end if;

    if v_dow is not null then
      v_day := v_today + ((v_dow - extract(isodow from v_today)::int + 7) % 7);
      if v_day <= v_today then
        v_day := v_day + 7;
      end if;
    end if;

    if v_day is not null then
      if v_day <= v_today then
        return;
      end if;

      return query
        select s.start_at, s.end_at, booking_label(s.start_at, v_tz)
        from check_availability(
          p_artist_id,
          (v_day::timestamp at time zone v_tz),
          ((v_day + 1)::timestamp at time zone v_tz),
          p_duration_min
        ) s
        where (v_after is null or (s.start_at at time zone v_tz)::time >= v_after)
          and (v_before is null or (s.start_at at time zone v_tz)::time < v_before)
        order by s.start_at
        limit 2;
      return;
    end if;
  end if;

  v_from := greatest(
    p_from,
    (v_today + 1)::timestamp at time zone v_tz
  );

  return query
    with slots as (
      select * from check_availability(p_artist_id, v_from, p_to, p_duration_min) s
      where (v_after is null or (s.start_at at time zone v_tz)::time >= v_after)
        and (v_before is null or (s.start_at at time zone v_tz)::time < v_before)
    ),
    first_per_day as (
      select distinct on ((sl.start_at at time zone v_tz)::date)
        sl.start_at,
        sl.end_at
      from slots sl
      order by (sl.start_at at time zone v_tz)::date, sl.start_at
    )
    select f.start_at, f.end_at, booking_label(f.start_at, v_tz)
    from first_per_day f
    order by f.start_at
    limit 2;
end;
$$;

revoke all on function booking_options(uuid, timestamptz, timestamptz, integer, text, text) from public;
grant execute on function booking_options(uuid, timestamptz, timestamptz, integer, text, text) to agent_rw, crm_app;

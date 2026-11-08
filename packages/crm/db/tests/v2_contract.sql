-- =============================================================================
-- VAIF CRM — v2 SQL contract tests
--
-- Run against a throwaway database with EVERY migration applied in order:
--   createdb vaif_v2_test
--   for f in migrations/*.sql; do psql -d vaif_v2_test -v ON_ERROR_STOP=1 -f "$f"; done
--   psql -d vaif_v2_test -v ON_ERROR_STOP=1 -f tests/v2_contract.sql
--
-- Every check raises on failure, so a non-zero exit is a broken contract.
-- The whole run is one transaction and is rolled back at the end.
-- =============================================================================

\set ON_ERROR_STOP on
\set QUIET on
set client_min_messages = warning;

begin;

create function pg_temp.ok(p_cond boolean, p_msg text) returns void
language plpgsql as $$
begin
  if p_cond is not true then
    raise exception 'FAIL: %', p_msg;
  end if;
end;
$$;

create function pg_temp.artist_a() returns uuid language sql immutable as
  $$ select '11111111-1111-4111-8111-111111111111'::uuid $$;
create function pg_temp.artist_b() returns uuid language sql immutable as
  $$ select '22222222-2222-4222-8222-222222222222'::uuid $$;

-- ---------------------------------------------------------------------------
-- Fixtures: two tenants with the same working hours
-- ---------------------------------------------------------------------------
insert into artists (id, name, wa_session_slug, status) values
  (pg_temp.artist_a(), 'Artist A', 'slug-a', 'live'),
  (pg_temp.artist_b(), 'Artist B', 'slug-b', 'live');

insert into artist_settings (artist_id, display_name, deposit_value, working_hours) values
  (pg_temp.artist_a(), 'Artist A', 30,
   '{"seg":["09:00-12:00","14:00-18:00"],"ter":["09:00-12:00","14:00-18:00"],
     "qua":["09:00-12:00","14:00-18:00"],"qui":["09:00-12:00","14:00-18:00"],
     "sex":["09:00-12:00","14:00-18:00"],"sab":["09:00-13:00"]}'::jsonb),
  (pg_temp.artist_b(), 'Artist B',
   '{"seg":["09:00-12:00","14:00-18:00"]}'::jsonb);

update artist_settings
   set reschedule_policy_hours = 48,
       cancellation_policy_hours = 24,
       payment_methods = '{pix,credit_card}',
       allow_hold_slot = false,
       guarantee_price = false,
       portfolio_url = 'https://instagram.com/artist_a'
 where artist_id = pg_temp.artist_a();

insert into price_book (artist_id, placement, body_zone, base_price_cents, session_duration_min)
values (pg_temp.artist_a(), 'antebraco', 'medio', 60000, 120),
       (pg_temp.artist_b(), 'antebraco', 'medio', 50000, 120);


-- ---------------------------------------------------------------------------
-- 1. Tenant resolution
-- ---------------------------------------------------------------------------
do $$
declare v jsonb;
begin
  select resolve_artist('slug-a') into v;
  perform pg_temp.ok(v->'artist'->>'name' = 'Artist A', 'resolve_artist returns the artist');
  perform pg_temp.ok(v->'settings'->>'display_name' = 'Artist A', 'resolve_artist returns settings');

  select resolve_artist('nope') into v;
  perform pg_temp.ok(v is null, 'resolve_artist returns nothing for an unknown slug');
end;
$$;


-- ---------------------------------------------------------------------------
-- 2. Leads: normalisation and one Lead per (artist, phone)
-- ---------------------------------------------------------------------------
do $$
declare
  l1 leads;
  l2 leads;
  l3 leads;
  d  integer;
begin
  select * into l1 from upsert_lead(pg_temp.artist_a(), '11 98765-4321', 'Ana', 'whatsapp');
  perform pg_temp.ok(l1.phone_e164 = '+5511987654321', 'phone normalised, got ' || l1.phone_e164);

  select * into l2 from upsert_lead(pg_temp.artist_a(), '+55 11 98765-4321', null, 'whatsapp');
  perform pg_temp.ok(l2.id = l1.id, 'upsert_lead is idempotent for the same phone');
  perform pg_temp.ok(l2.name = 'Ana', 'upsert_lead keeps the existing name');

  select * into l3 from upsert_lead(pg_temp.artist_b(), '11 98765-4321', 'Ana', 'whatsapp');
  perform pg_temp.ok(l3.id <> l1.id, 'the same phone is a different Lead for another Artist');

  select * into l3 from upsert_lead(
    pg_temp.artist_a(), '11 95555-0000', '  jOSE   DA  sILVA ', 'whatsapp');
  perform pg_temp.ok(l3.name = 'Jose da Silva',
    'upsert_lead normalises names, got ' || coalesce(l3.name, 'null'));
  perform pg_temp.ok(normalize_person_name('  jOSE   DA  SILVA ') = 'Jose da Silva',
    'normalize_person_name title-cases and collapses spaces');

  select count(*) - count(distinct (artist_id, phone_e164)) into d from leads;
  perform pg_temp.ok(d = 0, 'no duplicate (artist_id, phone_e164)');

  begin
    perform upsert_lead(pg_temp.artist_a(), '   ', null, null);
    raise exception 'FAIL: empty phone accepted';
  exception when invalid_parameter_value then
    null;  -- expected 22023
  end;
end;
$$;


-- ---------------------------------------------------------------------------
-- 3. Cross-tenant writes are rejected by the database
-- ---------------------------------------------------------------------------
do $$
declare
  lead_a uuid;
  rejected boolean := false;
begin
  select id into lead_a from leads where artist_id = pg_temp.artist_a();

  begin
    perform create_project(pg_temp.artist_b(), lead_a, 'braco', 'medio');
  exception when foreign_key_violation then
    rejected := true;
  end;
  perform pg_temp.ok(rejected, 'a Project cannot reference another Artist''s Lead');
end;
$$;


-- ---------------------------------------------------------------------------
-- 4. Quote lifecycle: one open quote, previous one voided
-- ---------------------------------------------------------------------------
do $$
declare
  lead_a uuid;
  p      projects;
  q1     quotes;
  q2     quotes;
  n      integer;
  stage  text;
  t      record;
begin
  select id into lead_a from leads
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511987654321';

  select * into p from create_project(pg_temp.artist_a(), lead_a, 'antebraco', 'medio');
  perform pg_temp.ok(p.stage_key = 'novo', 'a new Project starts in novo');

  select * into q1 from write_quote(pg_temp.artist_a(), p.id, 60000, 55000);
  perform pg_temp.ok(q1.offered_price_cents = 55000, 'quote keeps the offered price');
  perform pg_temp.ok(q1.status = 'open', 'quote is open');

  select * into q1 from write_quote(pg_temp.artist_a(), p.id, 60000, 55000);
  select count(*) into n from quotes where project_id = p.id and status = 'open';
  perform pg_temp.ok(n = 1, 're-sending the same price does not restart the quote');

  select * into q2 from write_quote(pg_temp.artist_a(), p.id, 60000, 50000);
  select count(*) into n from quotes where project_id = p.id and status = 'open';
  perform pg_temp.ok(n = 1, 'exactly one open quote per Project');
  select count(*) into n from quotes where project_id = p.id and status = 'void';
  perform pg_temp.ok(n = 1, 'the superseded quote is voided, not deleted');

  select stage_key into stage from projects where id = p.id;
  perform pg_temp.ok(stage = 'orcamento_enviado', 'sending a quote advances the stage');

  -- start_turn surfaces the live quote and the persisted reference analysis.
  update projects
     set reference_analysis = '{"resumo": "Lettering New York", "estilo": "lettering"}'::jsonb
   where id = p.id;

  select * into t from start_turn(
    pg_temp.artist_a(), 'slug-a:+5511987654321', '+5511987654321');
  perform pg_temp.ok(t.project_id = p.id, 'start_turn reuses the open Project');
  perform pg_temp.ok(t.reference_analysis->>'estilo' = 'lettering',
    'start_turn returns the last vision analysis');
  perform pg_temp.ok(t.quoted_price_cents = 50000,
    'start_turn returns the open quote price, got ' || coalesce(t.quoted_price_cents::text, 'null'));
end;
$$;


-- ---------------------------------------------------------------------------
-- 5. Manual deposit: request -> confirm -> returning customer
-- ---------------------------------------------------------------------------
do $$
declare
  p     projects;
  b     calendar_entries;
  pay   payments;
  pay2  payments;
  stage text;
  n     integer;
begin
  select * into p from projects
   where artist_id = pg_temp.artist_a()
   order by created_at desc limit 1;
  update projects set tattoo_type = 'new' where id = p.id;

  -- The deposit gate: no signal before the slot is actually booked. The RPC
  -- returns an empty set (the agent must book first), it does not raise.
  select * into pay from request_deposit(pg_temp.artist_a(), p.id, 18000, 'pix');
  perform pg_temp.ok(pay.id is null, 'a deposit before booking returns no payment');

  select * into b from book_slot(
    pg_temp.artist_a(), p.id, timestamptz '2027-05-10 10:00:00-03');
  perform pg_temp.ok(b.id is not null, 'a slot is booked before the deposit');

  select * into pay from request_deposit(pg_temp.artist_a(), p.id, 18000, 'pix');
  perform pg_temp.ok(pay.status = 'requested', 'deposit starts as requested');

  select stage_key into stage from projects where id = p.id;
  perform pg_temp.ok(stage = 'aguardando_deposito',
    'the booked project waits for the deposit');

  select * into pay2 from confirm_deposit(pg_temp.artist_a(), p.id, 'pix', 'manual-1');
  perform pg_temp.ok(pay2.status = 'confirmed', 'deposit is confirmed');
  perform pg_temp.ok(pay2.confirmed_at is not null, 'confirmed_at is set');
  perform pg_temp.ok(pay2.external_ref = 'manual-1', 'external_ref is stored (future Pix seam)');

  select stage_key into stage from projects where id = p.id;
  perform pg_temp.ok(stage = 'agendado', 'confirming the deposit advances to agendado');

  select count(*) into n from returning_customers
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511987654321';
  perform pg_temp.ok(n = 0, 'confirming a deposit does not mark the client as returning');

  select * into p from complete_project(pg_temp.artist_a(), p.id);
  perform pg_temp.ok(p.stage_key = 'fechado', 'completing the tattoo closes the project');
  perform pg_temp.ok(p.closed_at is not null, 'completing the tattoo sets closed_at');

  select count(*) into n from returning_customers
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511987654321'
     and source = 'tattoo_completed';
  perform pg_temp.ok(n = 1, 'completing the tattoo marks the client as returning');

  select count(*) into n from events
   where project_id = p.id and type = 'deposit_confirmed';
  perform pg_temp.ok(n = 1, 'the deposit event was written');

  select count(*) into n from events
   where project_id = p.id and type = 'tattoo_completed';
  perform pg_temp.ok(n = 1, 'the completion event was written');
end;
$$;


-- ---------------------------------------------------------------------------
-- 6. Calendar: exclusion constraint is the real guard
-- ---------------------------------------------------------------------------
do $$
declare
  lead_a uuid;
  lead_b uuid;
  p1     projects;
  p2     projects;
  p3     projects;
  b1     calendar_entries;
  b2     calendar_entries;
  b3     calendar_entries;
  stage  text;
begin
  select id into lead_a from leads
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511987654321';
  select id into lead_b from leads
   where artist_id = pg_temp.artist_b() and phone_e164 = '+5511987654321';

  -- A fresh project: the deposit test above closes its project with
  -- complete_project(), so reusing it would stay fechado.
  select * into p1 from create_project(pg_temp.artist_a(), lead_a, 'braco', 'pequeno');
  select * into p2 from create_project(pg_temp.artist_a(), lead_a, 'costas', 'grande');
  select * into p3 from create_project(pg_temp.artist_b(), lead_b, 'antebraco', 'medio');
  update projects set tattoo_type = 'new' where id in (p1.id, p2.id, p3.id);

  -- 2027-03-01 is a Monday; 10:00 local (UTC-3) = 13:00 UTC.
  select * into b1 from book_slot(pg_temp.artist_a(), p1.id, timestamptz '2027-03-01 10:00:00-03');
  perform pg_temp.ok(b1.google_sync_pending, 'a new booking queues a Google Calendar sync');
  perform pg_temp.ok(b1.id is not null, 'booking is created');
  perform pg_temp.ok(b1.kind = 'booking', 'entry kind is booking');

  select stage_key into stage from projects where id = p1.id;
  perform pg_temp.ok(stage = 'aguardando_deposito', 'booking waits for the deposit');

  -- Same Artist, overlapping window: must be refused, not raise.
  select * into b2 from book_slot(pg_temp.artist_a(), p2.id, timestamptz '2027-03-01 11:00:00-03');
  perform pg_temp.ok(b2.id is null, 'overlapping booking for the same Artist is refused');

  -- Different Artist, same instant: allowed.
  select * into b3 from book_slot(pg_temp.artist_b(), p3.id, timestamptz '2027-03-01 10:00:00-03');
  perform pg_temp.ok(b3.id is not null, 'the same instant is free for another Artist');

  -- Adjacent booking is not an overlap ('[)' range).
  select * into b2 from book_slot(pg_temp.artist_a(), p2.id, timestamptz '2027-03-01 12:00:00-03');
  perform pg_temp.ok(b2.id is not null, 'a booking starting exactly at the end is allowed');
end;
$$;


-- ---------------------------------------------------------------------------
-- 7. Availability reflects bookings and working hours
-- ---------------------------------------------------------------------------
do $$
declare n integer;
begin
  select count(*) into n from check_availability(
    pg_temp.artist_a(), timestamptz '2027-03-01 00:00:00-03',
    timestamptz '2027-03-02 00:00:00-03', 120)
  where start_at = timestamptz '2027-03-01 10:00:00-03';
  perform pg_temp.ok(n = 0, 'a booked slot disappears from check_availability');

  select count(*) into n from check_availability(
    pg_temp.artist_a(), timestamptz '2027-03-01 00:00:00-03',
    timestamptz '2027-03-02 00:00:00-03', 120)
  where start_at = timestamptz '2027-03-01 14:00:00-03';
  perform pg_temp.ok(n = 1, 'a free working-hours slot is offered');

  select count(*) into n from check_availability(
    pg_temp.artist_a(), timestamptz '2027-03-01 00:00:00-03',
    timestamptz '2027-03-02 00:00:00-03', 120)
  where start_at >= timestamptz '2027-03-01 12:00:00-03'
    and start_at <  timestamptz '2027-03-01 14:00:00-03';
  perform pg_temp.ok(n = 0, 'slots are not offered during the lunch break');

  -- Sunday has no working hours at all.
  select count(*) into n from check_availability(
    pg_temp.artist_a(), timestamptz '2027-03-07 00:00:00-03',
    timestamptz '2027-03-08 00:00:00-03', 120);
  perform pg_temp.ok(n = 0, 'no slots on a day without working hours');
end;
$$;


-- ---------------------------------------------------------------------------
-- 8. Reschedule, cancel, block
-- ---------------------------------------------------------------------------
do $$
declare
  b     calendar_entries;
  b2    calendar_entries;
  moved calendar_entries;
  blocked calendar_entries;
  n     integer;
begin
  select * into b from calendar_entries
   where artist_id = pg_temp.artist_a() and kind = 'booking' and active
     and start_at = timestamptz '2027-03-01 10:00:00-03';

  -- Overlapping the 12:00 booking must fail.
  select * into b2 from reschedule_booking(pg_temp.artist_a(), b.id,
    timestamptz '2027-03-01 11:00:00-03');
  perform pg_temp.ok(b2.id is null, 'rescheduling onto a taken slot is refused');

  select * into moved from reschedule_booking(pg_temp.artist_a(), b.id,
    timestamptz '2027-03-01 15:00:00-03');
  perform pg_temp.ok(moved.start_at = timestamptz '2027-03-01 15:00:00-03', 'booking moved');
  perform pg_temp.ok(moved.end_at - moved.start_at = interval '2 hours', 'duration preserved');
  perform pg_temp.ok(moved.google_sync_pending, 'a reschedule queues a Google Calendar sync');

  select * into blocked from block_time(pg_temp.artist_a(),
    timestamptz '2027-03-01 15:00:00-03', timestamptz '2027-03-01 16:00:00-03', 'Almoço');
  perform pg_temp.ok(blocked.id is null, 'a block cannot overlap a booking');

  -- The rescheduled booking now occupies 15:00-17:00, so block right after it.
  select * into blocked from block_time(pg_temp.artist_a(),
    timestamptz '2027-03-01 17:00:00-03', timestamptz '2027-03-01 17:30:00-03', 'Médico');
  perform pg_temp.ok(blocked.id is not null, 'a free window can be blocked');
  perform pg_temp.ok(blocked.kind = 'block', 'block kind');

  select * into b2 from cancel_booking(pg_temp.artist_a(), moved.id, 'Lead desistiu');
  perform pg_temp.ok(b2.active = false, 'a cancelled booking is deactivated, not deleted');
  perform pg_temp.ok(b2.reason = 'Lead desistiu', 'cancellation reason kept');
  perform pg_temp.ok(b2.google_sync_pending, 'a cancellation queues a Google Calendar sync');

  select count(*) into n from calendar_entries
   where artist_id = pg_temp.artist_a() and kind = 'booking' and active;
  perform pg_temp.ok(n = 2, 'the non-cancelled bookings remain active');
end;
$$;


-- ---------------------------------------------------------------------------
-- 8b. Agent date offers: two different days, or two slots on a requested day
-- ---------------------------------------------------------------------------
do $$
declare
  o     record;
  n     integer;
  days  integer;
begin
  select count(*) into n
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120
  );
  perform pg_temp.ok(n = 2, 'booking_options returns exactly two options');

  select count(distinct (start_at at time zone 'America/Sao_Paulo')::date) into days
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120
  );
  perform pg_temp.ok(days = 2, 'the two options are on different days');

  select * into o
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120
  )
  limit 1;
  perform pg_temp.ok(o.label like '%, dia __/__ às __ horas',
    'booking_options returns a formatted label, got ' || coalesce(o.label, 'null'));

  select count(*) into n
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120,
    '2027-04-02'
  );
  perform pg_temp.ok(n = 2, 'a requested day returns up to two slots');

  select count(distinct (start_at at time zone 'America/Sao_Paulo')::date) into days
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120,
    '2027-04-02'
  );
  perform pg_temp.ok(days = 1, 'a requested day stays on that day');

  select count(*) into n
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120,
    'prefiro sábado'
  )
  where extract(isodow from start_at at time zone 'America/Sao_Paulo')::int <> 6;
  perform pg_temp.ok(n = 0, 'a weekday word in the lead message resolves to that weekday');

  select count(distinct (start_at at time zone 'America/Sao_Paulo')::date) into days
  from booking_options(
    pg_temp.artist_a(),
    timestamptz '2027-04-01 00:00:00-03',
    timestamptz '2027-04-10 00:00:00-03',
    120,
    'é minha segunda tatuagem'
  );
  perform pg_temp.ok(days = 2, '"segunda tatuagem" is not treated as Monday');
end;
$$;


-- ---------------------------------------------------------------------------
-- 9. Promotion: activation snapshots the price book, quote is a promotion
-- ---------------------------------------------------------------------------
do $$
declare
  p      projects;
  promo  promotions;
  q      quotes;
  n      integer;
begin
  insert into promotions (artist_id, type_key, name, multiplier, audience, status)
  values (pg_temp.artist_a(), 'bogo_same_value', 'Leve 2', 2.0, 'all', 'draft')
  returning * into promo;

  select * into promo from activate_promotion(pg_temp.artist_a(), promo.id);
  perform pg_temp.ok(promo.status = 'active', 'promotion activates');

  select count(*) into n from promotion_prices where promotion_id = promo.id;
  perform pg_temp.ok(n = 1, 'activation snapshots the current price book');

  select * into p from projects where artist_id = pg_temp.artist_a()
   order by created_at desc limit 1;

  select * into q from write_promotion_quote(
    pg_temp.artist_a(), p.id, promo.id, 'antebraco', 'medio');
  perform pg_temp.ok(q.kind = 'promotion', 'quote kind is promotion');
  perform pg_temp.ok(q.promotion_id = promo.id, 'quote references the promotion');
  perform pg_temp.ok(q.offered_price_cents = 120000, 'offered = 2x base, got ' || q.offered_price_cents);
  perform pg_temp.ok(q.second_cap_cents = 60000, 'second tattoo cap = base price');
  perform pg_temp.ok(q.valid_until > now(), 'promotion quote has an expiry');

  -- Cross-tenant promotion use is refused.
  begin
    perform write_promotion_quote(pg_temp.artist_b(), p.id, promo.id, 'antebraco', 'medio');
    raise exception 'FAIL: another Artist could use this promotion';
  exception when foreign_key_violation then
    null;
  end;

  select * into promo from end_promotion(pg_temp.artist_a(), promo.id);
  perform pg_temp.ok(promo.status = 'ended', 'promotion ends');

  select count(*) into n from quotes
   where promotion_id = promo.id and status = 'open';
  perform pg_temp.ok(n = 0, 'ending a promotion voids its open quotes');
end;
$$;


-- ---------------------------------------------------------------------------
-- 10. LGPD: anonymize keeps history, removes identity
-- ---------------------------------------------------------------------------
do $$
declare
  lead_a uuid;
  a      leads;
  n      integer;
begin
  select id into lead_a from leads
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511987654321';

  perform link_channel(pg_temp.artist_a(), lead_a, 'whatsapp', 'default:5511987654321');
  perform resolve_chat_session(pg_temp.artist_a(), 'default:5511987654321', lead_a);

  select * into a from anonymize_lead(pg_temp.artist_a(), lead_a);
  perform pg_temp.ok(a.name is null, 'name is erased');
  perform pg_temp.ok(a.phone_raw is null, 'raw phone is erased');
  perform pg_temp.ok(a.phone_e164 like 'anon:%', 'phone replaced by a tombstone');
  perform pg_temp.ok(a.anonymized_at is not null, 'anonymized_at is set');

  select count(*) into n from lead_channels where lead_id = lead_a;
  perform pg_temp.ok(n = 0, 'channel identifiers are deleted');
  select count(*) into n from chat_sessions where lead_id = lead_a;
  perform pg_temp.ok(n = 0, 'chat sessions are deleted');
  select count(*) into n from returning_customers
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511987654321';
  perform pg_temp.ok(n = 0, 'the returning-customer entry is deleted');

  -- Business history survives.
  select count(*) into n from projects where lead_id = lead_a;
  perform pg_temp.ok(n > 0, 'Projects are kept');
  select count(*) into n from payments p
    join projects pr on pr.id = p.project_id
   where pr.lead_id = lead_a;
  perform pg_temp.ok(n > 0, 'Payments are kept');
end;
$$;


-- ---------------------------------------------------------------------------
-- 10b. Hard delete: removes the client and every attached identifier
-- ---------------------------------------------------------------------------
do $$
declare
  l       leads;
  p       projects;
  deleted boolean;
  n       integer;
begin
  insert into leads (id, artist_id, name, phone_e164)
  values (gen_random_uuid(), pg_temp.artist_a(), 'Delete Me', '+5511900000000')
  returning * into l;

  insert into projects (id, artist_id, lead_id, stage_key)
  values (gen_random_uuid(), pg_temp.artist_a(), l.id, 'qualificando')
  returning * into p;

  insert into lead_channels (artist_id, lead_id, channel, external_id)
  values (pg_temp.artist_a(), l.id, 'whatsapp', 'default:5511900000000');

  insert into chat_sessions (artist_id, chat_id, lead_id)
  values (pg_temp.artist_a(), 'default:5511900000000', l.id);

  insert into chat_buffers (artist_id, chat_id, pending)
  values (pg_temp.artist_a(), 'default:5511900000000', 'oi');

  insert into agent_memory.chat_memory (session_id, message)
  values (l.id::text, '{"type":"human","content":"oi"}'::jsonb);

  insert into returning_customers (artist_id, phone_e164, name)
  values (pg_temp.artist_a(), '+5511900000000', 'Delete Me')
  on conflict (artist_id, phone_e164) do nothing;

  deleted := delete_lead(pg_temp.artist_a(), l.id, 'artist');
  perform pg_temp.ok(deleted, 'delete_lead returns true');

  select count(*) into n from leads where id = l.id;
  perform pg_temp.ok(n = 0, 'the Lead is deleted');
  select count(*) into n from projects where lead_id = l.id;
  perform pg_temp.ok(n = 0, 'the Projects are deleted');
  select count(*) into n from lead_channels where lead_id = l.id;
  perform pg_temp.ok(n = 0, 'the channel identifiers are deleted');
  select count(*) into n from chat_sessions where lead_id = l.id;
  perform pg_temp.ok(n = 0, 'the chat sessions are deleted');
  select count(*) into n from chat_buffers
   where artist_id = pg_temp.artist_a() and chat_id = 'default:5511900000000';
  perform pg_temp.ok(n = 0, 'the debounce buffer is deleted');
  select count(*) into n from agent_memory.chat_memory where session_id = l.id::text;
  perform pg_temp.ok(n = 0, 'the agent memory is deleted');
  select count(*) into n from returning_customers
   where artist_id = pg_temp.artist_a() and phone_e164 = '+5511900000000';
  perform pg_temp.ok(n = 0, 'the returning-customer entry is deleted');

  deleted := delete_lead(pg_temp.artist_a(), l.id, 'artist');
  perform pg_temp.ok(not deleted, 'deleting an unknown Lead returns false');
end;
$$;


-- ---------------------------------------------------------------------------
-- 11. Events are partitioned and carry the tenant
-- ---------------------------------------------------------------------------
do $$
declare n integer; part text;
begin
  select to_regclass('public.events_' || to_char(now() at time zone 'UTC', 'YYYY_MM'))::text
    into part;
  perform pg_temp.ok(part is not null, 'a partition exists for the current month');

  select count(*) into n from events where artist_id = pg_temp.artist_a();
  perform pg_temp.ok(n > 0, 'events were written for the Artist');

  select count(*) into n from pg_indexes
   where tablename = 'chat_memory' and indexdef like '%session_id%';
  perform pg_temp.ok(n >= 1, 'chat_memory is indexed by session_id');
end;
$$;


-- ---------------------------------------------------------------------------
-- 12. Agent turn: start_turn, scratch state, debounce buffer
-- ---------------------------------------------------------------------------
do $$
declare
  t1    record;
  t2    record;
  b     chat_buffers;
  cal   calendar_entries;
  state jsonb;
  o     record;
  n     integer;
begin
  -- Debounce: burst messages are joined, presence is recorded.
  select * into b from chat_buffer_accumulate(
    pg_temp.artist_a(), 'slug-a:5511911112222', 'oi', now());
  perform pg_temp.ok(b.pending = 'oi', 'first buffered message is stored');

  select * into b from chat_buffer_accumulate(
    pg_temp.artist_a(), 'slug-a:5511911112222', 'quero uma tattoo', now());
  perform pg_temp.ok(b.pending = E'oi\nquero uma tattoo',
    'burst messages are joined, got ' || b.pending);

  select * into b from chat_buffer_typing(
    pg_temp.artist_a(), 'slug-a:5511911112222', 'typing', now());
  perform pg_temp.ok(b.typing_state = 'typing', 'typing state is recorded');

  -- First contact creates the Lead, the Project and the chat session.
  select * into t1 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '11 91111-2222', 'Ana', 'whatsapp');
  perform pg_temp.ok(t1.is_new, 'the first turn is new');
  perform pg_temp.ok(t1.project_id is not null, 'a Project is opened');
  perform pg_temp.ok(t1.stage_key = 'novo', 'the Project starts in novo');
  perform pg_temp.ok(t1.agent_state = '{}'::jsonb, 'scratch state starts empty');
  perform pg_temp.ok(chat_owner(pg_temp.artist_a(), 'slug-a:5511911112222') = 'agent',
    'the chat session is created and owned by the agent');

  -- Second turn: same Lead and same open Project, not new.
  select * into t2 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '5511911112222', null, 'whatsapp');
  perform pg_temp.ok(not t2.is_new, 'the second turn is not new');
  perform pg_temp.ok(t2.lead_id = t1.lead_id, 'the same Lead is reused');
  perform pg_temp.ok(t2.project_id = t1.project_id, 'the same open Project is reused');

  -- The artist can silence the SDR for this person.
  update leads set agent_blocked_at = now() where id = t1.lead_id;
  select * into t2 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '5511911112222');
  perform pg_temp.ok(t2.agent_blocked, 'start_turn reports the silenced Lead');

  update leads set agent_blocked_at = null where id = t1.lead_id;
  select * into t2 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '5511911112222');
  perform pg_temp.ok(not t2.agent_blocked, 'unblocking resumes the agent');

  -- Load Offer must always produce a row, even with no promotion or quote.
  select * into o from active_offer_for_project(pg_temp.artist_a(), t1.project_id);
  perform pg_temp.ok(o.promo_active is false, 'no active promotion reports promo_active=false');
  perform pg_temp.ok(o.open_quote_valid is false, 'no live quote reports open_quote_valid=false');

  -- Scratch state merges instead of replacing.
  state := patch_chat_state(pg_temp.artist_a(), 'slug-a:5511911112222',
    '{"processo_explicado": true}'::jsonb);
  state := patch_chat_state(pg_temp.artist_a(), 'slug-a:5511911112222',
    '{"audio_count": 1}'::jsonb);
  perform pg_temp.ok(state->>'processo_explicado' = 'true',
    'state is sticky across patches');
  perform pg_temp.ok((state->>'audio_count')::int = 1, 'numeric flags are stored');

  select * into t2 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '5511911112222');
  perform pg_temp.ok(t2.agent_state->>'processo_explicado' = 'true',
    'start_turn returns the scratch state');

  -- Clearing the buffer keeps the row but drops the pending burst.
  select * into b from chat_buffer_clear(pg_temp.artist_a(), 'slug-a:5511911112222');
  perform pg_temp.ok(b.pending is null, 'clear drops the pending text');

  -- A closed Project is history: the next conversation opens a new one.
  perform set_project_stage(pg_temp.artist_a(), t1.project_id, 'fechado');
  select * into t2 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '5511911112222');
  perform pg_temp.ok(t2.project_id <> t1.project_id,
    'a new Project starts after the previous one was closed');

  -- Expired buffers are purged.
  update chat_buffers set expires_at = now() - interval '1 minute'
   where artist_id = pg_temp.artist_a() and chat_id = 'slug-a:5511911112222';
  perform pg_temp.ok(purge_chat_buffers() >= 1, 'purge removes the expired buffer');
  select count(*) into n from chat_buffers where chat_id = 'slug-a:5511911112222';
  perform pg_temp.ok(n = 0, 'the purged buffer is gone');

  -- The prompt can read the whole price book without table access.
  select count(*) into n from list_prices(pg_temp.artist_a());
  perform pg_temp.ok(n = 1, 'list_prices returns the current rows for the Artist');

  -- Deposit state is surfaced to the prompt, but only after booking.
  update projects set tattoo_type = 'new', placement = 'braco', body_zone = 'pequeno' where id = t2.project_id;
  select * into cal from book_slot(
    pg_temp.artist_a(), t2.project_id, timestamptz '2027-06-01 10:00:00-03', 120, 'staff');
  perform pg_temp.ok(cal.id is not null, 'the agent books a slot before the deposit');

  perform request_deposit(pg_temp.artist_a(), t2.project_id, 18000, 'pix');
  select * into t2 from start_turn(
    pg_temp.artist_a(), 'slug-a:5511911112222', '5511911112222');
  perform pg_temp.ok(t2.deposit_status = 'aguardando_confirmacao',
    'start_turn reports a requested deposit, got ' || coalesce(t2.deposit_status,'null'));
  perform pg_temp.ok(t2.deposit_amount_cents = 18000, 'start_turn reports the deposit amount');
end;
$$;


-- ---------------------------------------------------------------------------
-- 13. patch_project: deltas only, stage through the guard, audit trail
-- ---------------------------------------------------------------------------
do $$
declare
  p projects;
  n integer;
begin
  select * into p from projects
   where artist_id = pg_temp.artist_a()
     and stage_key not in ('fechado','perdido')
   order by created_at desc limit 1;

  select * into p from patch_project(
    pg_temp.artist_a(), p.id,
    p_placement    => 'costas',
    p_body_zone    => 'grande',
    p_style        => 'realismo',
    p_first_tattoo => true,
    p_tattoo_type  => 'cover_up',
    p_meaning      => 'homenagem');

  perform pg_temp.ok(p.placement = 'costas', 'placement is patched');
  perform pg_temp.ok(p.first_tattoo, 'booleans are patched');
  perform pg_temp.ok(p.tattoo_type = 'cover_up', 'tattoo_type is patched');
  perform pg_temp.ok(p.meaning = 'homenagem', 'meaning is patched');

  select count(*) into n from events
   where project_id = p.id and type = 'project_updated';
  perform pg_temp.ok(n = 1, 'one project_updated event for the turn, got ' || n);

  -- Re-sending the same value is silent.
  select * into p from patch_project(pg_temp.artist_a(), p.id, p_placement => 'costas');
  select count(*) into n from events
   where project_id = p.id and type = 'project_updated';
  perform pg_temp.ok(n = 1, 'unchanged fields do not log again');

  -- Stage changes are delegated, so closed_at and the event stay consistent.
  select * into p from patch_project(pg_temp.artist_a(), p.id, p_stage_key => 'qualificando');
  perform pg_temp.ok(p.stage_key = 'qualificando', 'stage is patched');
  select count(*) into n from events
   where project_id = p.id and type = 'stage_changed'
     and payload->>'stage' = 'qualificando';
  perform pg_temp.ok(n = 1, 'the stage change is audited');

  -- A tool may advance the stage during the turn; the patch must not roll it back.
  perform set_project_stage(pg_temp.artist_a(), p.id, 'aguardando_deposito');
  select * into p from patch_project(
    pg_temp.artist_a(), p.id,
    p_stage_key      := 'orcamento_enviado',
    p_expected_stage := 'qualificando');
  perform pg_temp.ok(p.stage_key = 'aguardando_deposito',
    'the patch does not override a stage a tool already moved');

  -- Unknown stage values are refused by the lookup table.
  begin
    perform patch_project(pg_temp.artist_a(), p.id, p_stage_key => 'nao_existe');
    raise exception 'FAIL: unknown stage accepted';
  exception when foreign_key_violation then
    null;
  end;

  -- Cross-tenant patching is refused.
  begin
    perform patch_project(pg_temp.artist_b(), p.id, p_style => 'blackwork');
    raise exception 'FAIL: another Artist could patch this Project';
  exception when foreign_key_violation then
    null;
  end;
end;
$$;


-- ---------------------------------------------------------------------------
-- 14. Roles exist and the agent has no table access
-- ---------------------------------------------------------------------------
do $$
declare n integer;
begin
  select count(*) into n from pg_roles
   where rolname in ('migrator','crm_app','agent_rw','analytics_ro');
  perform pg_temp.ok(n = 4, 'four NOLOGIN roles exist');

  select count(distinct table_name) into n from information_schema.role_table_grants
   where grantee = 'agent_rw' and table_schema = 'public';
  perform pg_temp.ok(n = 0, 'agent_rw has no public table grants, got ' || n);

  perform pg_temp.ok(
    has_table_privilege('agent_rw', 'agent_memory.chat_memory', 'select')
    and has_table_privilege('agent_rw', 'agent_memory.chat_memory', 'insert')
    and has_table_privilege('agent_rw', 'agent_memory.chat_memory', 'update')
    and has_table_privilege('agent_rw', 'agent_memory.chat_memory', 'delete'),
    'agent_rw has DML on its memory table');

  select count(*) into n from information_schema.role_table_grants
   where grantee = 'crm_app' and table_schema = 'public';
  perform pg_temp.ok(n > 0, 'crm_app has table grants');

  select count(*) into n from information_schema.routine_privileges
   where grantee = 'agent_rw' and routine_schema = 'public'
     and routine_name in (
       'start_turn','upsert_project','patch_project','patch_chat_state',
       'chat_buffer_accumulate','chat_buffer_typing','chat_buffer_state',
       'chat_buffer_clear','list_prices'
     );
  perform pg_temp.ok(n = 9, 'agent_rw can execute every turn/buffer RPC, got ' || n);
end;
$$;

-- ---------------------------------------------------------------------------
-- 15. Admin surface: calendar read model, onboarding, suspend/resume, reset
-- ---------------------------------------------------------------------------
do $$
declare
  a     artists;
  n     integer;
  j     jsonb;
begin
  -- An available slot from the artist's working hours, in the admin shape.
  select count(*) into n
    from list_calendar(pg_temp.artist_a(),
                       timestamptz '2027-03-01 00:00:00-03',
                       timestamptz '2027-03-02 00:00:00-03', 120)
   where type = 'available';
  perform pg_temp.ok(n > 0, 'list_calendar returns available slots');

  -- A booking shows up as `booked` with the lead name.
  select count(*) into n
    from list_calendar(pg_temp.artist_a(),
                       timestamptz '2027-03-01 00:00:00-03',
                       timestamptz '2027-03-02 00:00:00-03', 120)
   where type = 'booked';
  perform pg_temp.ok(n >= 1, 'list_calendar returns the booked slot, got ' || n);

  -- Onboarding token: stub -> onboarding, valid for 24h.
  insert into artists (id, name, wa_session_slug, status, onboarding_token)
  values ('99999999-9999-4999-8999-999999999999', 'Stub Artist',
          'stub-slug', 'stub', 'tok-1');

  select * into a from consume_onboarding_token('tok-1');
  perform pg_temp.ok(a.status = 'onboarding', 'consume_onboarding_token moves stub -> onboarding');

  update artists set status = 'live' where id = a.id;

  select * into a from suspend_artist(a.id);
  perform pg_temp.ok(a.status = 'suspended', 'suspend_artist suspends a live artist');

  select * into a from resume_artist(a.id);
  perform pg_temp.ok(a.status = 'live', 'resume_artist restores the artist');

  select * into a from suspend_artist(a.id);
  select * into a from suspend_artist(a.id);
  perform pg_temp.ok(a.id is null, 'suspending a non-live artist is a no-op');

  -- The destructive admin reset is scoped to one tenant.
  j := reset_leads('slug-b');
  perform pg_temp.ok((j->>'artist_slug') = 'slug-b', 'reset_leads echoes the slug');
  perform pg_temp.ok((j->>'leads')::int >= 0, 'reset_leads reports a lead count');

  -- Shared admin tokens are app-only, invisible to analytics.
  perform pg_temp.ok(has_table_privilege('crm_app', 'admin_settings', 'select'),
    'crm_app can read admin_settings');
  perform pg_temp.ok(not has_table_privilege('analytics_ro', 'admin_settings', 'select'),
    'analytics_ro cannot read admin_settings');
end;
$$;


-- ---------------------------------------------------------------------------
-- 16. Availability truth, reschedule and visible drops
-- ---------------------------------------------------------------------------
do $$
declare
  lead_row   leads;
  p_ready    projects;
  p_draft    projects;
  opts       record;
  slot1      record;
  slot2      record;
  booking1   calendar_entries;
  booking2   calendar_entries;
  turn       record;
  phone      text := '11 94444-3333';
  dropped    agent_ingest_drops;
  suspended  jsonb;
begin
  select * into lead_row from upsert_lead(pg_temp.artist_a(), phone, 'Remarcação', 'whatsapp');

  select * into p_draft from create_project(pg_temp.artist_a(), lead_row.id, null, null);
  select * into opts from booking_options_v2(
    pg_temp.artist_a(), p_draft.id, now(), now() + interval '30 days', 120, null, null);
  perform pg_temp.ok(opts.status = 'not_ready',
    'an unqualified project is not_ready, got ' || coalesce(opts.status, 'null'));
  perform pg_temp.ok(opts.missing like '%local%',
    'not_ready names the missing field, got ' || coalesce(opts.missing, 'null'));

  select * into p_ready from create_project(
    pg_temp.artist_a(), lead_row.id, 'antebraco', 'medio', 'fine_line', true, null, 'new');

  select * into opts from booking_options_v2(
    pg_temp.artist_a(), p_ready.id, now(), now() + interval '30 days', 120, null, null);
  perform pg_temp.ok(opts.status = 'ready',
    'a qualified project is ready, got ' || coalesce(opts.status, 'null'));

  select * into slot1 from booking_options_v2(
    pg_temp.artist_a(), p_ready.id, now(), now() + interval '30 days', 120, null, null)
   limit 1;

  select * into booking1 from book_slot(
    pg_temp.artist_a(), p_ready.id, slot1.start_at, 120);
  perform pg_temp.ok(booking1.id is not null, 'book_slot creates the first booking');

  select * into turn from start_turn(
    pg_temp.artist_a(), 'slug-a:5511944443333', phone, 'Remarcação', 'whatsapp');
  perform pg_temp.ok(turn.active_booking_id = booking1.id,
    'start_turn exposes the phone-level active booking');
  perform pg_temp.ok(turn.active_booking_start = booking1.start_at,
    'start_turn exposes the active booking start');

  select * into slot2 from booking_options_v2(
    pg_temp.artist_a(), p_ready.id, now(), now() + interval '30 days', 120, null, null)
   where start_at <> booking1.start_at
   limit 1;
  perform pg_temp.ok(slot2.start_at is not null, 'a second slot is available for the reschedule');

  select * into booking2 from reschedule_booking(
    pg_temp.artist_a(), booking1.id, slot2.start_at);
  perform pg_temp.ok(booking2.id = booking1.id, 'the reschedule keeps the same booking row');
  perform pg_temp.ok(booking2.start_at = slot2.start_at, 'the booking uses the requested slot');
  perform pg_temp.ok(booking2.google_sync_pending, 'the reschedule queues a Google sync');

  select * into booking1 from get_active_booking_by_phone(pg_temp.artist_a(), phone);
  perform pg_temp.ok(booking1.id = booking2.id,
    'get_active_booking_by_phone returns the rescheduled booking');

  -- A suspended artist still resolves, so the workflow can log the drop.
  insert into artists (id, name, wa_session_slug, status)
  values ('33333333-3333-4333-8333-333333333333', 'Paused Artist', 'slug-paused', 'suspended');
  select resolve_artist('slug-paused') into suspended;
  perform pg_temp.ok(suspended is not null, 'resolve_artist returns a suspended artist');
  perform pg_temp.ok(suspended->'artist'->>'status' = 'suspended',
    'resolve_artist keeps the suspended status');

  select * into dropped from log_ingest_drop(
    pg_temp.artist_a(), 'slug-a', phone, 'slug-a:5511944443333', 'artist_suspended',
    '{"test": true}'::jsonb);
  perform pg_temp.ok(dropped.id is not null, 'log_ingest_drop writes a visible row');
  perform pg_temp.ok(dropped.phone_e164 = '+5511944443333',
    'log_ingest_drop normalises the phone, got ' || coalesce(dropped.phone_e164, 'null'));

  -- A new handoff hands chat ownership to the artist and starts the SLA clock.
  perform set_project_stage(pg_temp.artist_a(), p_ready.id, 'aguardando_artista', 'agent');
  perform pg_temp.ok(
    (select owner from chat_sessions
      where artist_id = pg_temp.artist_a() and lead_id = lead_row.id limit 1) = 'artist',
    'a handoff moves chat ownership to the artist');
  perform pg_temp.ok(
    (select handoff_at is not null and handoff_reminded_at is null and handoff_escalated_at is null
       from projects where id = p_ready.id),
    'a new handoff starts the SLA clock at zero');
end;
$$;


-- ---------------------------------------------------------------------------
-- 17. Grounded turns — facts contract + shadow log
-- ---------------------------------------------------------------------------
do $$
declare
  lead_g  leads;
  proj_g  projects;
  f       jsonb;
  tid     uuid := gen_random_uuid();
  logged  uuid;
begin
  select * into lead_g from upsert_lead(
    pg_temp.artist_a(), '11 93333-2222', 'Fatos', 'whatsapp');
  select * into proj_g from create_project(
    pg_temp.artist_a(), lead_g.id, 'antebraco', 'medio', 'fine_line', true, null, 'new');

  f := build_turn_facts(
    pg_temp.artist_a(), proj_g.id, '11 93333-2222', 'ask_price', '{}'::jsonb);

  perform pg_temp.ok(f->'pricing'->>'status' = 'ready',
    'facts price row is ready, got ' || coalesce(f->'pricing'->>'status', 'null'));
  perform pg_temp.ok((f->'pricing'->>'base_cents')::int = 60000,
    'facts carry the table price');
  perform pg_temp.ok((f->'pricing'->>'deposit_cents')::int = 18000,
    'facts compute the 30% deposit');
  perform pg_temp.ok(jsonb_array_length(f->'availability'->'slots') > 0,
    'facts carry real slots');
  perform pg_temp.ok(f->'project'->>'placement' = 'antebraco',
    'facts carry the project fields');
  perform pg_temp.ok(f->'studio' ? 'city', 'facts carry the studio block');
  perform pg_temp.ok((f->'conditions'->>'reschedule_hours')::int = 48,
    'facts carry the reschedule policy, got ' || coalesce(f->'conditions'->>'reschedule_hours', 'null'));
  perform pg_temp.ok(
    (f->'conditions'->'payment_methods') ? 'pix',
    'facts carry the configured payment methods');
  perform pg_temp.ok((f->'booking'->>'active')::boolean = false,
    'facts report no booking for this lead');

  logged := log_agent_turn(
    tid, pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:test', 'shadow',
    'ask_price', 0.9, f, 'resposta da beatriz', '[]'::jsonb, '[]'::jsonb,
    'shadow', 'test-model', 12, false, '[]'::jsonb);

  perform pg_temp.ok(logged is not null, 'log_agent_turn returns the row id');
  perform pg_temp.ok(
    (select count(*) from agent_turn_log where turn_id = tid) = 1,
    'one audit row per turn');
  perform pg_temp.ok(
    (select mode from agent_turn_log where id = logged) = 'shadow',
    'mode is persisted');
  perform pg_temp.ok(
    (select facts->'pricing'->>'status' from agent_turn_log where id = logged) = 'ready',
    'facts are persisted as jsonb');

  perform pg_temp.ok(
    (select grounding_mode from artist_settings where artist_id = pg_temp.artist_a()) = 'off',
    'grounding is off by default');
end;
$$;


-- ---------------------------------------------------------------------------
-- 18. Grounded turn application (enforce path)
-- ---------------------------------------------------------------------------
do $$
declare
  lead_g   leads;
  proj_g   projects;
  cover_g  projects;
  res      jsonb;
  p_row    projects;
  l_row    leads;
  c_state  jsonb;
begin
  select * into lead_g from upsert_lead(
    pg_temp.artist_a(), '11 92222-1111', 'Placeholder', 'whatsapp');
  select * into proj_g from create_project(
    pg_temp.artist_a(), lead_g.id, null, null);

  res := apply_grounded_turn(
    pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:grounded',
    gen_random_uuid(), 'qualify', '{}'::jsonb,
    '{"placement":"antebraco","body_zone":"medio","style":"fine_line","tattoo_type":"nova","name":"Joana"}'::jsonb,
    '{"pricing":{"status":"ready"},"booking":{"created_this_turn":false}}'::jsonb,
    '[{"type":"question","field":"style"}]'::jsonb,
    'Qual estilo?', 'accepted', '[]'::jsonb, 'oi', 0.9);

  perform pg_temp.ok(res->>'stage_key' = 'qualificando',
    'grounded fields move novo -> qualificando, got ' || coalesce(res->>'stage_key', 'null'));

  select * into p_row from projects where id = proj_g.id;
  perform pg_temp.ok(p_row.placement = 'antebraco' and p_row.style = 'fine_line',
    'grounded entities persist on the project');
  perform pg_temp.ok(p_row.tattoo_type = 'new', 'grounded type is mapped');

  select * into l_row from leads where id = lead_g.id;
  perform pg_temp.ok(l_row.name = 'Joana', 'grounded stated name saved');

  select agent_state into c_state from chat_sessions
   where artist_id = pg_temp.artist_a() and lead_id = lead_g.id;
  perform pg_temp.ok(c_state->>'pending_question' = 'style',
    'pending question comes from the claim');

  res := apply_grounded_turn(
    pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:grounded',
    gen_random_uuid(), 'ask_price', '{}'::jsonb, '{}'::jsonb,
    '{"pricing":{"status":"ready"}}'::jsonb,
    '[{"type":"price","cents":60000}]'::jsonb,
    'Fica R$ 600', 'accepted', '[]'::jsonb, 'quanto?', 0.8);
  perform pg_temp.ok(res->>'stage_key' = 'orcamento_enviado',
    'a price claim moves the project to orcamento_enviado');

  select * into cover_g from create_project(
    pg_temp.artist_a(), lead_g.id, 'braco', 'medio', 'fine_line', true, null, 'cover_up');
  res := apply_grounded_turn(
    pg_temp.artist_a(), lead_g.id, cover_g.id, 'slug-a:grounded',
    gen_random_uuid(), 'coverup', '{"needs_human":true}'::jsonb, '{}'::jsonb,
    '{"project":{"tattoo_type":"cover_up"}}'::jsonb,
    '[{"type":"handoff"}]'::jsonb,
    'Vou te passar', 'accepted', '[]'::jsonb, 'quero cobrir', 0.9);

  perform pg_temp.ok(res->>'stage_key' = 'aguardando_artista',
    'cover-up hands off');
  select * into p_row from projects where id = cover_g.id;
  perform pg_temp.ok(p_row.handoff_at is not null and p_row.handoff_resolved_at is null,
    'the handoff lifecycle starts');
  perform pg_temp.ok(
    (select agent_blocked_at from leads where id = lead_g.id) is not null,
    'the agent is silenced on handoff');
end;
$$;


-- ---------------------------------------------------------------------------
-- 19. Idempotent grounded writes
-- ---------------------------------------------------------------------------
do $$
declare
  lead_g leads;
  proj_g projects;
  tid    uuid := gen_random_uuid();
  r1     jsonb;
  r2     jsonb;
  p_row  projects;
begin
  select * into lead_g from upsert_lead(
    pg_temp.artist_a(), '11 91111-2222', 'Idem', 'whatsapp');
  select * into proj_g from create_project(
    pg_temp.artist_a(), lead_g.id, null, null);

  r1 := apply_grounded_turn_once(
    pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:idem', tid,
    'qualify', '{}'::jsonb, '{"placement":"braco"}'::jsonb,
    '{"pricing":{"status":"ready"}}'::jsonb, '[]'::jsonb,
    'primeira', 'accepted', '[]'::jsonb, 'oi', 0.9);

  r2 := apply_grounded_turn_once(
    pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:idem', tid,
    'qualify', '{}'::jsonb, '{"placement":"perna"}'::jsonb,
    '{"pricing":{"status":"ready"}}'::jsonb, '[]'::jsonb,
    'segunda', 'accepted', '[]'::jsonb, 'oi', 0.9);

  perform pg_temp.ok(r1->>'stage_key' = r2->>'stage_key',
    'a repeated turn returns the stored result');
  perform pg_temp.ok((r2->>'duplicate')::boolean is true,
    'the repeated turn is marked duplicate');
  perform pg_temp.ok(
    (select count(*) from agent_turn_actions where turn_id = tid) = 1,
    'one idempotency row per turn');

  select * into p_row from projects where id = proj_g.id;
  perform pg_temp.ok(p_row.placement = 'braco',
    'a repeated turn does not overwrite the first write, got ' || coalesce(p_row.placement, 'null'));
end;
$$;


-- ---------------------------------------------------------------------------
-- 20. Grounded writes: quote and deposit from facts
-- ---------------------------------------------------------------------------
do $$
declare
  lead_g  leads;
  proj_g  projects;
  res     jsonb;
  slot    record;
  q       quotes;
  pay     payments;
begin
  select * into lead_g from upsert_lead(
    pg_temp.artist_a(), '11 90000-1111', 'Writes', 'whatsapp');
  select * into proj_g from create_project(
    pg_temp.artist_a(), lead_g.id, 'antebraco', 'medio', 'fine_line', true, null, 'new');

  res := apply_grounded_turn_once(
    pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:writes', gen_random_uuid(),
    'ask_price', '{}'::jsonb, '{}'::jsonb,
    '{"pricing":{"status":"ready","base_cents":60000,"offered_cents":60000,"deposit_cents":18000,"open_quote":false},"booking":{"active":false,"created_this_turn":false}}'::jsonb,
    '[{"type":"price","cents":60000}]'::jsonb,
    'Fica R$ 600', 'accepted', '[]'::jsonb, 'quanto?', 0.9);

  perform pg_temp.ok((res->>'quote_written')::boolean is true,
    'a price claim writes a quote');

  select * into q from quotes
   where project_id = proj_g.id and status = 'open'
   order by created_at desc limit 1;
  perform pg_temp.ok(q.offered_price_cents = 60000,
    'the quote amount comes from the facts');

  select * into slot from booking_options_v2(
    pg_temp.artist_a(), proj_g.id, now(), now() + interval '30 days', 120, null, null)
   where status = 'ready'
   limit 1;

  if slot.start_at is not null then
    perform book_slot(pg_temp.artist_a(), proj_g.id, slot.start_at, 120);

    res := apply_grounded_turn_once(
      pg_temp.artist_a(), lead_g.id, proj_g.id, 'slug-a:writes', gen_random_uuid(),
      'close', '{}'::jsonb, '{}'::jsonb,
      jsonb_build_object(
        'pricing', jsonb_build_object('status','ready','base_cents',60000,'offered_cents',60000,'deposit_cents',18000,'open_quote',true),
        'booking', jsonb_build_object('active',true,'created_this_turn',true,'start_at',slot.start_at)
      ),
      '[{"type":"deposit","cents":18000}]'::jsonb,
      'O sinal fica R$ 180', 'accepted', '[]'::jsonb, 'pode ser', 0.9);

    perform pg_temp.ok((res->>'deposit_requested')::boolean is true,
      'a deposit claim requests the deposit');

    select * into pay from payments
     where project_id = proj_g.id and kind = 'deposit'
     order by requested_at desc limit 1;
    perform pg_temp.ok(pay.amount_cents = 18000,
      'the deposit amount comes from the facts');
  end if;
end;
$$;


-- ---------------------------------------------------------------------------
-- 21. Context expansion: returning clients and portfolio
-- ---------------------------------------------------------------------------
do $$
declare
  lead_g  leads;
  proj_g  projects;
  f       jsonb;
begin
  select * into lead_g from upsert_lead(
    pg_temp.artist_a(), '11 98888-7777', 'Retorno', 'whatsapp');
  select * into proj_g from create_project(
    pg_temp.artist_a(), lead_g.id, 'antebraco', 'medio', 'fine_line', true, null, 'new');

  perform book_slot(
    pg_temp.artist_a(), proj_g.id,
    timestamptz '2024-01-10 10:00:00-03', 120);

  perform backfill_returning_customers(pg_temp.artist_a());

  perform pg_temp.ok(exists (
    select 1 from returning_customers
    where artist_id = pg_temp.artist_a() and phone_e164 = '+5511988887777'
  ), 'a past booking becomes a returning customer');

  f := build_turn_facts(
    pg_temp.artist_a(), proj_g.id, '11 98888-7777', 'qualify', '{}'::jsonb);

  perform pg_temp.ok((f->'client'->>'returning')::boolean is true,
    'facts mark a returning client');
  perform pg_temp.ok((f->'client'->>'has_past_booking')::boolean is true,
    'facts expose the past booking');
  perform pg_temp.ok(f->'portfolio'->>'url' = 'https://instagram.com/artist_a',
    'facts carry the portfolio link, got ' || coalesce(f->'portfolio'->>'url', 'null'));
end;
$$;

\echo 'ALL V2 CONTRACT TESTS PASSED'

rollback;

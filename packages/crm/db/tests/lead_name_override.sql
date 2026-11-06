-- =============================================================================
-- VAIF CRM — set_lead_name contract tests
--
-- Run against a database that has every migration applied, including
-- 0021_lead_name_override.sql:
--   psql -d vaif_crm_test -v ON_ERROR_STOP=1 -f tests/lead_name_override.sql
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

insert into artists (id, name, wa_session_slug, status)
values ('33333333-3333-4333-8333-333333333333', 'Name Test', 'name-test', 'live')
on conflict (id) do nothing;

-- The WhatsApp profile name is stored as the placeholder on first contact.
select * from upsert_lead(
  '33333333-3333-4333-8333-333333333333',
  '+5511900000001',
  'Deus No Controle De Tudo',
  'whatsapp'
);
select pg_temp.ok(
  (select name from leads where phone_e164 = '+5511900000001') = 'Deus No Controle de Tudo',
  'the profile name is stored as a placeholder'
);

-- The name the lead states replaces it.
select * from set_lead_name(
  '33333333-3333-4333-8333-333333333333',
  '+5511900000001',
  'criszander'
);
select pg_temp.ok(
  (select name from leads where phone_e164 = '+5511900000001') = 'Criszander',
  'the stated name replaces the profile placeholder'
);

-- A first name must not downgrade a fuller name.
update leads set name = 'Gustavo Vilela' where phone_e164 = '+5511900000001';
select * from set_lead_name(
  '33333333-3333-4333-8333-333333333333',
  '+5511900000001',
  'Gustavo'
);
select pg_temp.ok(
  (select name from leads where phone_e164 = '+5511900000001') = 'Gustavo Vilela',
  'a first name does not downgrade a full name'
);

-- An accent-only variant is the same name.
update leads set name = 'Jessica' where phone_e164 = '+5511900000001';
select * from set_lead_name(
  '33333333-3333-4333-8333-333333333333',
  '+5511900000001',
  'Jéssica'
);
select pg_temp.ok(
  (select name from leads where phone_e164 = '+5511900000001') = 'Jessica',
  'an accent-only variant keeps the existing name'
);

-- A missing name is a no-op.
select * from set_lead_name(
  '33333333-3333-4333-8333-333333333333',
  '+5511900000001',
  null
);
select pg_temp.ok(
  (select name from leads where phone_e164 = '+5511900000001') = 'Jessica',
  'a null name leaves the lead unchanged'
);

rollback;

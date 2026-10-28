-- =============================================================================
-- VAIF CRM — v1 → v2 staff users and notes (2026-10-28 cutover)
--
-- Run AFTER `php artisan migrate` has created `users`/`notes` in the v2
-- database, as the owner:
--
--   psql -U postgres -d vaif_crm -v ON_ERROR_STOP=1 -f v1_to_v2_users.sql
--
-- Notes point at Leads; a note whose Lead was not migrated is skipped, so the
-- FK is never violated. Idempotent.
-- =============================================================================

\set ON_ERROR_STOP on

create extension if not exists dblink;

select dblink_connect('v1', 'dbname=postgres');

insert into users (id, name, email, email_verified_at, password, role,
                   artist_id, remember_token, created_at, updated_at)
select t.id, t.name, t.email, t.email_verified_at, t.password, t.role,
       t.artist_id, t.remember_token, t.created_at, t.updated_at
from dblink('v1', $q$
  select id, name, email, email_verified_at, password, role,
         artist_id, remember_token, created_at, updated_at
  from users
$q$) as t(id bigint, name text, email text, email_verified_at timestamp,
          password text, role text, artist_id uuid, remember_token text,
          created_at timestamp, updated_at timestamp)
on conflict (id) do nothing;

select setval('users_id_seq', greatest(coalesce((select max(id) from users), 1), 1), true);

insert into notes (id, lead_id, author_id, body, created_at, updated_at)
select t.id, t.lead_id, t.author_id, t.body, t.created_at, t.updated_at
from dblink('v1', $q$
  select id, lead_id, author_id, body, created_at, updated_at from notes
$q$) as t(id uuid, lead_id uuid, author_id bigint, body text,
          created_at timestamp, updated_at timestamp)
where exists (select 1 from leads where leads.id = t.lead_id)
on conflict (id) do nothing;

select dblink_disconnect('v1');

do $$
begin
  raise notice 'v1 users migrated: %', (select count(*) from users);
end;
$$;

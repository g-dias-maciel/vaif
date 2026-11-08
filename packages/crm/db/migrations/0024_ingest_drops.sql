-- =============================================================================
-- Never drop an inbound message silently
--
-- `resolve_artist()` only matched `status in ('stub','onboarding','live')`.
-- When an artist was suspended the workflow took the "artist not found"
-- branch, logged to the n8n console, and the message disappeared: no reply, no
-- alert, no row in the CRM.
--
-- Now a suspended artist is resolved normally (with `status = 'suspended'`), so
-- the workflow can recognise a paused account and record the inbound in
-- `agent_ingest_drops` before stopping. `v_open_ingest_drops` feeds the
-- dashboard badge; `crm:notify-ingest-drops` e-mails the admin digest.
-- =============================================================================

create table if not exists agent_ingest_drops (
  id           uuid primary key default gen_random_uuid(),
  artist_id    uuid references artists(id) on delete cascade,
  session_slug text,
  phone_e164   text,
  chat_id      text,
  reason       text not null,
  payload      jsonb not null default '{}'::jsonb,
  created_at   timestamptz not null default now(),
  notified_at  timestamptz
);

create index if not exists agent_ingest_drops_open
  on agent_ingest_drops (created_at)
  where notified_at is null;

create index if not exists agent_ingest_drops_artist
  on agent_ingest_drops (artist_id, created_at desc);

comment on table agent_ingest_drops is
  'Inbound WhatsApp messages the agent intentionally did not answer (paused artist, unknown session, etc.). Evidence that a drop was visible, not silent.';

create or replace function log_ingest_drop(
  p_artist_id uuid,
  p_session   text,
  p_phone     text,
  p_chat_id   text,
  p_reason    text,
  p_payload   jsonb default '{}'::jsonb
)
returns setof agent_ingest_drops
language plpgsql security definer
set search_path = public, pg_temp
as $$
declare
  v_row agent_ingest_drops;
begin
  insert into agent_ingest_drops (
    artist_id, session_slug, phone_e164, chat_id, reason, payload
  ) values (
    p_artist_id, p_session, normalize_phone(p_phone), p_chat_id,
    coalesce(nullif(btrim(p_reason), ''), 'unknown'),
    coalesce(p_payload, '{}'::jsonb)
  )
  returning * into v_row;

  return next v_row;
end;
$$;

revoke all on function log_ingest_drop(uuid, text, text, text, text, jsonb) from public;
grant execute on function log_ingest_drop(uuid, text, text, text, text, jsonb) to agent_rw, crm_app;

create or replace view v_open_ingest_drops as
  select d.id,
         d.artist_id,
         a.name as artist_name,
         d.session_slug,
         d.phone_e164,
         d.chat_id,
         d.reason,
         d.created_at,
         d.notified_at
  from agent_ingest_drops d
  left join artists a on a.id = d.artist_id
  where d.notified_at is null
  order by d.created_at desc;

grant select on agent_ingest_drops to crm_app, analytics_ro;
grant update (notified_at) on agent_ingest_drops to crm_app;
grant select on v_open_ingest_drops to crm_app, analytics_ro;


-- -----------------------------------------------------------------------------
-- resolve_artist — include suspended artists so the workflow can route them to
-- a visible pause branch instead of the unknown-session branch.
-- -----------------------------------------------------------------------------
create or replace function resolve_artist(p_slug text)
returns setof jsonb
language sql stable security definer
set search_path = public, pg_temp
as $$
  select jsonb_build_object('artist', to_jsonb(a), 'settings', to_jsonb(s))
  from artists a
  left join artist_settings s on s.artist_id = a.id
  where a.wa_session_slug = p_slug
  limit 1;
$$;

revoke all on function resolve_artist(text) from public;
grant execute on function resolve_artist(text) to agent_rw, crm_app;

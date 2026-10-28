-- =============================================================================
-- VAIF CRM — v2 roles and grants (design §7)
--
-- Applied after 0001 and 0002, by the schema owner. Idempotent.
--
-- This file creates NOLOGIN group roles and grants. The actual login roles (with
-- passwords) are created per environment, outside the repo:
--
--   create role crm_app_login  login password '<from Coolify env>' in role crm_app;
--   create role agent_login    login password '<from Coolify env>' in role agent_rw;
--   create role analytics_login login password '<from Coolify env>' in role analytics_ro;
--
-- The app must NOT connect as the owner. `crm_app` has DML but no DDL, so
-- `php artisan migrate` has to run as `migrator` from a deploy step
-- (set AUTO_MIGRATE=false on the container once the app is ported).
--
-- RLS is deliberately not used (ADR-0002): isolation is artist_id + composite
-- foreign keys + these role boundaries.
-- =============================================================================

do $$
begin
  if not exists (select 1 from pg_roles where rolname = 'migrator') then
    create role migrator nologin;
  end if;
  if not exists (select 1 from pg_roles where rolname = 'crm_app') then
    create role crm_app nologin;
  end if;
  if not exists (select 1 from pg_roles where rolname = 'agent_rw') then
    create role agent_rw nologin;
  end if;
  if not exists (select 1 from pg_roles where rolname = 'analytics_ro') then
    create role analytics_ro nologin;
  end if;
end
$$;

-- Nobody but the owner creates objects in public.
revoke create on schema public from public;
grant usage on schema public to crm_app, agent_rw, analytics_ro;

-- ---------------------------------------------------------------------------
-- crm_app: full DML on domain tables, no DDL
-- ---------------------------------------------------------------------------
grant select, insert, update, delete on all tables in schema public to crm_app;
grant usage, select on all sequences in schema public to crm_app;

-- ---------------------------------------------------------------------------
-- analytics_ro: read-only
-- ---------------------------------------------------------------------------
grant select on all tables in schema public to analytics_ro;

-- ---------------------------------------------------------------------------
-- agent_rw: no domain table access at all; only the RPC surface.
-- (Every RPC is SECURITY DEFINER, so it runs with the owner's privileges.)
--
-- The one exception is the agent's own memory schema: n8n's Postgres Chat
-- Memory node creates its table at runtime, so it needs CREATE on that schema.
-- It is not domain data and lives outside `public`.
-- ---------------------------------------------------------------------------
grant usage, create on schema agent_memory to agent_rw;
alter table agent_memory.chat_memory owner to agent_rw;
alter sequence agent_memory.chat_memory_id_seq owner to agent_rw;
grant select, insert, update, delete on agent_memory.chat_memory to agent_rw;
grant usage, select on sequence agent_memory.chat_memory_id_seq to agent_rw;

-- The memory node uses the unqualified name `chat_memory`.
alter role agent_rw set search_path = agent_memory, public;

do $$
declare
  v_fn text;
  v_fns text[] := array[
    'resolve_artist(text)',
    'upsert_lead(uuid,text,text,text)',
    'link_channel(uuid,uuid,text,text)',
    'resolve_chat_session(uuid,text,uuid)',
    'take_chat_control(uuid,text,uuid)',
    'return_chat_control(uuid,text)',
    'chat_owner(uuid,text)',
    'chat_buffer_accumulate(uuid,text,text,timestamptz)',
    'chat_buffer_typing(uuid,text,text,timestamptz)',
    'chat_buffer_state(uuid,text)',
    'chat_buffer_clear(uuid,text)',
    'patch_chat_state(uuid,text,jsonb)',
    'start_turn(uuid,text,text,text,text)',
    'upsert_project(uuid,uuid,text)',
    'patch_project(uuid,uuid,text,text,text,text,boolean,text,text,text[],text,text,text)',
    'create_project(uuid,uuid,text,text,text,boolean,text,text,text[],text)',
    'set_project_stage(uuid,uuid,text,text)',
    'lookup_price(uuid,text,text)',
    'list_prices(uuid)',
    'write_quote(uuid,uuid,integer,integer,numeric,integer,timestamptz,text)',
    'write_promotion_quote(uuid,uuid,uuid,text,text,text)',
    'accept_quote(uuid,uuid,uuid,text)',
    'request_deposit(uuid,uuid,integer,text,text)',
    'confirm_deposit(uuid,uuid,text,text,text)',
    'check_availability(uuid,timestamptz,timestamptz,integer)',
    'book_slot(uuid,uuid,timestamptz,integer,text)',
    'reschedule_booking(uuid,uuid,timestamptz,text)',
    'cancel_booking(uuid,uuid,text,text)',
    'block_time(uuid,timestamptz,timestamptz,text,text)',
    'unblock_time(uuid,uuid)',
    'activate_promotion(uuid,uuid)',
    'pause_promotion(uuid,uuid)',
    'end_promotion(uuid,uuid)',
    'is_returning_customer(uuid,text)',
    'import_returning_customer(uuid,text,text,timestamptz)',
    'active_offer_for_project(uuid,uuid)'
  ];
begin
  foreach v_fn in array v_fns loop
    begin
      execute format('revoke all on function %s from public', v_fn);
      execute format('grant execute on function %s to agent_rw, crm_app', v_fn);
    exception when undefined_function then
      raise warning 'grant skipped, function % not found', v_fn;
    end;
  end loop;
end
$$;

-- Staff-only / maintenance functions: never granted to the agent.
revoke all on function anonymize_lead(uuid,uuid,text) from public;
grant execute on function anonymize_lead(uuid,uuid,text) to crm_app;
revoke all on function purge_chat_memory(interval) from public;
grant execute on function purge_chat_memory(interval) to crm_app;
revoke all on function create_event_partitions(integer) from public;
grant execute on function create_event_partitions(integer) to crm_app;
revoke all on function purge_chat_buffers() from public;
grant execute on function purge_chat_buffers() to crm_app;

-- Admin workflows (agenda, onboarding, QA reset) run as crm_app, never as the
-- agent. These mutate tenants or wipe data, so they stay off agent_rw.
revoke all on function consume_onboarding_token(text) from public;
grant execute on function consume_onboarding_token(text) to crm_app;
revoke all on function suspend_artist(uuid) from public;
grant execute on function suspend_artist(uuid) to crm_app;
revoke all on function resume_artist(uuid) from public;
grant execute on function resume_artist(uuid) to crm_app;
revoke all on function list_calendar(uuid,timestamptz,timestamptz,integer) from public;
grant execute on function list_calendar(uuid,timestamptz,timestamptz,integer) to crm_app;
revoke all on function reset_leads(text) from public;
grant execute on function reset_leads(text) to crm_app;

-- Helpers must not be callable through the API surface.
revoke all on function log_event(uuid,uuid,uuid,text,text,jsonb) from public;
revoke all on function normalize_phone(text) from public;

-- Future objects (new tables created by the migrator) inherit the same grants.
alter default privileges in schema public
  grant select, insert, update, delete on tables to crm_app;
alter default privileges in schema public
  grant usage, select on sequences to crm_app;
alter default privileges in schema public
  grant select on tables to analytics_ro;

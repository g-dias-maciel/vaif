-- =============================================================================
-- Agent calibration — Phase 1: copilot / draft mode
--
-- `reply_mode` controls what the in-CRM agent does with a grounded turn:
--   off   = do not even draft
--   draft = draft the reply, store it, never send or apply actions
--   auto  = validate, apply backend actions and send (current enforce path)
--
-- `agent_drafts` keeps the draft and pairs it with the artist's real WhatsApp
-- reply for comparison. The artist reply is captured by the webhook as a
-- `fromMe` message; the comparison is written by the queue worker.
-- =============================================================================

alter table artist_settings
  add column if not exists reply_mode text not null default 'draft';

alter table artist_settings
  drop constraint if exists artist_settings_reply_mode_check;

alter table artist_settings
  add constraint artist_settings_reply_mode_check
  check (reply_mode in ('off', 'draft', 'auto'));

comment on column artist_settings.reply_mode is
  'off = no drafting; draft = draft and store only; auto = apply and send.';

create table if not exists agent_drafts (
  id                 uuid primary key default gen_random_uuid(),
  artist_id          uuid not null references artists(id) on delete cascade,
  lead_id            uuid references leads(id) on delete cascade,
  project_id         uuid references projects(id) on delete cascade,
  chat_id            text not null,
  turn_id            uuid not null,
  lead_text          text not null,
  lead_msg_at        timestamptz,
  intent             text,
  intent_confidence  numeric(3, 2),
  facts              jsonb not null default '{}'::jsonb,
  draft_reply        text not null,
  claims             jsonb not null default '[]'::jsonb,
  validation         jsonb not null default '{}'::jsonb,
  simulated_actions  jsonb not null default '{}'::jsonb,
  model              text,
  prompt_version     text,
  latency_ms         integer,
  status             text not null default 'pending',
  artist_reply       text,
  artist_replied_at  timestamptz,
  response_seconds   integer,
  artist_claims      jsonb not null default '[]'::jsonb,
  artist_validation  jsonb not null default '{}'::jsonb,
  comparison         jsonb,
  compared_at        timestamptz,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now()
);

create unique index if not exists agent_drafts_turn
  on agent_drafts (artist_id, turn_id);

create index if not exists agent_drafts_chat
  on agent_drafts (artist_id, chat_id, created_at desc);

create index if not exists agent_drafts_status
  on agent_drafts (artist_id, status, created_at desc);

create index if not exists agent_drafts_lead
  on agent_drafts (lead_id, created_at desc);

comment on table agent_drafts is
  'Copilot drafts: what the agent would have sent, paired with the artist actual reply for calibration.';

grant select, insert, update, delete on agent_drafts to crm_app;
grant select on agent_drafts to analytics_ro;

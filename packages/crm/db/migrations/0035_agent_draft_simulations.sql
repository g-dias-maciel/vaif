-- =============================================================================
-- Historical/calibration simulations for agent drafts
--
-- `source = live`       real copilot draft generated from a live message
-- `source = simulated`  a mock Beatriz reply generated for an older customer
--                       message in the calibration view; never sent/paired
--
-- `lead_message_id` points at agent_memory.chat_memory.id so a simulated draft
-- can be shown under the exact customer message.
-- =============================================================================

alter table agent_drafts
  add column if not exists source text not null default 'live';

alter table agent_drafts
  add column if not exists lead_message_id bigint;

create index if not exists agent_drafts_source
  on agent_drafts (artist_id, source, created_at desc);

create index if not exists agent_drafts_lead_message
  on agent_drafts (lead_id, lead_message_id);

comment on column agent_drafts.source is
  'live = real draft; simulated = retrospective mock for calibration display.';
comment on column agent_drafts.lead_message_id is
  'agent_memory.chat_memory.id of the customer message this draft answers.';

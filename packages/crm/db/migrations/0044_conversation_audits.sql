-- =============================================================================
-- Observe mode — Phase 4: post-hoc methodology audits
--
-- One row per audited transcript. The transcript hash makes the audit
-- idempotent: the same conversation is not sent to the model twice, and a new
-- message produces a new hash. Audits are staff-only and never shown to the
-- Lead or the Artist.
-- =============================================================================

create table if not exists conversation_audits (
  id               uuid primary key default gen_random_uuid(),
  artist_id        uuid not null references artists(id) on delete cascade,
  lead_id          uuid references leads(id) on delete cascade,
  chat_id          text not null,
  transcript_hash  text not null,
  status           text not null default 'ready' check (status in ('queued', 'ready', 'failed')),
  score            numeric(5, 2),
  summary          text,
  steps            jsonb not null default '[]'::jsonb,
  message_count    integer not null default 0,
  last_message_at  timestamptz,
  model            text,
  generated_at     timestamptz,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now()
);

create unique index if not exists conversation_audits_transcript
  on conversation_audits (chat_id, transcript_hash);

create index if not exists conversation_audits_artist
  on conversation_audits (artist_id, generated_at desc);

create index if not exists conversation_audits_lead
  on conversation_audits (lead_id, generated_at desc);

comment on table conversation_audits is
  'Staff-only methodology audit of an observe-mode conversation; one row per transcript hash.';

grant select, insert, update, delete on conversation_audits to crm_app;
grant select on conversation_audits to analytics_ro;

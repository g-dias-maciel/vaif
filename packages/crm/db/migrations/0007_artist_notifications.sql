-- =============================================================================
-- Artist attention e-mails
--
-- The CRM scheduler e-mails the artist when a lead is waiting for them:
--   * a project in `aguardando_artista` (handoff), or
--   * a requested deposit that needs the PIX to be confirmed.
--
-- The timestamp columns are the dedup markers so the same item is not e-mailed
-- every minute. `handoff_notified_at` is cleared when the project leaves the
-- handoff stage, so a future handoff sends a new e-mail.
-- =============================================================================

alter table projects
  add column if not exists handoff_notified_at timestamptz;

alter table payments
  add column if not exists deposit_notified_at timestamptz;

create index if not exists projects_handoff_pending
  on projects (artist_id)
  where stage_key = 'aguardando_artista' and handoff_notified_at is null;

create index if not exists payments_deposit_pending
  on payments (artist_id)
  where kind = 'deposit' and status = 'requested' and deposit_notified_at is null;

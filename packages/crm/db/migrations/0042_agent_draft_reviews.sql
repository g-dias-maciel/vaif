-- Staff review of a copilot draft: was it good, or should Beatriz have
-- answered differently? The corrected reply becomes the calibration target.
create table if not exists agent_draft_reviews (
  id              uuid primary key default gen_random_uuid(),
  artist_id       uuid not null references artists(id) on delete cascade,
  agent_draft_id  uuid not null unique references agent_drafts(id) on delete cascade,
  lead_id         uuid references leads(id) on delete set null,
  reviewer_id     bigint references users(id) on delete set null,
  verdict         text not null default 'needs_fix' check (verdict in ('good', 'needs_fix')),
  category        text,
  note            text,
  corrected_reply text,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

create index if not exists agent_draft_reviews_artist_updated_idx
  on agent_draft_reviews (artist_id, updated_at desc);

grant select, insert, update, delete on agent_draft_reviews to crm_app;

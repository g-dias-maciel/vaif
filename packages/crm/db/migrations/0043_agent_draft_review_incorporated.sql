-- Marks a staff review as already incorporated into the prompt/rules.
alter table agent_draft_reviews
  add column if not exists incorporated_at timestamptz;

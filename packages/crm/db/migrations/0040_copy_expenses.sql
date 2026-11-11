-- =============================================================================
-- Copy expenses between months
--
-- `copied_from_id` points at the source expense, and the unique index prevents
-- copying the same source row into the same target month twice.
-- =============================================================================

alter table public.billing_expenses
  add column if not exists copied_from_id uuid;

create unique index if not exists billing_expenses_copied_from_month_key
  on public.billing_expenses (copied_from_id, competence_month)
  where copied_from_id is not null;

create index if not exists billing_expenses_copied_from
  on public.billing_expenses (copied_from_id);

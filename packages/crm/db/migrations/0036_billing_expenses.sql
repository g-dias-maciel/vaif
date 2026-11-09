-- =============================================================================
-- Finance — expenses (saídas) and monthly view source
--
-- Revenue already lives in billing_invoices / billing_payments. This table
-- adds the cost side: one row per expense, assigned to a competence month.
-- Overdue is derived from status = 'pending' and due_date < today.
--
-- Mirrors app/database/migrations/2026_11_09_000003_create_billing_expenses.php
-- for the SQLite test schema.
-- =============================================================================

create table if not exists public.billing_expenses (
  id                uuid primary key default gen_random_uuid(),
  description       text not null,
  vendor            text,
  category          text not null default 'other'
                    check (category in ('ads','software','payroll','contractors','taxes','office','other')),
  amount_cents      bigint not null check (amount_cents > 0),
  competence_month  date not null,
  due_date          date not null,
  paid_at           date,
  status            text not null default 'pending'
                    check (status in ('pending','paid','cancelled')),
  payment_method    text
                    check (payment_method is null or payment_method in ('pix','credit_card','boleto','transfer','cash','other')),
  recurrence        text not null default 'one_off'
                    check (recurrence in ('one_off','monthly')),
  recurrence_key    text,
  notes             text,
  created_by        bigint references public.users(id) on delete set null,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now()
);

create index if not exists billing_expenses_month on public.billing_expenses (competence_month desc);
create index if not exists billing_expenses_due on public.billing_expenses (due_date);
create index if not exists billing_expenses_status on public.billing_expenses (status);
create index if not exists billing_expenses_category on public.billing_expenses (category);
create index if not exists billing_expenses_recurrence on public.billing_expenses (recurrence_key);

-- A recurring series can only have one row per month.
create unique index if not exists billing_expenses_recurring_month_key
  on public.billing_expenses (recurrence_key, competence_month)
  where recurrence_key is not null;

drop trigger if exists trg_billing_expenses_touch on public.billing_expenses;
create trigger trg_billing_expenses_touch before update on public.billing_expenses
  for each row execute function touch_updated_at();

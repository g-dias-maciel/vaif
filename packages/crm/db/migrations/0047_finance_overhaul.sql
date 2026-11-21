-- =============================================================================
-- Finance overhaul — Notion-style month view, partner distributions,
-- invoice approval + delivery, and reimbursement tracking.
--
-- Adds:
--   * finance_partners / finance_distributions — partner profit payouts and
--     reimbursements for expenses paid from a partner's personal account.
--   * finance_months — optional Guardado override (historical months use 0).
--   * billing_expenses.paid_by_partner_id / reimbursed_* — who paid and whether
--     the partner has been reimbursed.
--   * billing_invoices approval/delivery/reminder columns + subtotal/discount.
--   * prospects.billing_address / tax_id — invoice "Cobrança para" block.
--   * billing_settings invoice lead time, approval e-mail and WhatsApp texts.
--
-- Mirrors app/database/migrations/2026_11_21_000001_finance_overhaul.php for
-- the SQLite test schema.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Partners
-- -----------------------------------------------------------------------------
create table if not exists public.finance_partners (
  id          uuid primary key default gen_random_uuid(),
  name        text not null,
  share_bps   integer not null default 0 check (share_bps between 0 and 10000),
  active      boolean not null default true,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create index if not exists finance_partners_active on public.finance_partners (active);

-- -----------------------------------------------------------------------------
-- 2. Distributions — profit payouts and reimbursements per month
-- -----------------------------------------------------------------------------
create table if not exists public.finance_distributions (
  id                  uuid primary key default gen_random_uuid(),
  partner_id          uuid not null references public.finance_partners(id) on delete restrict,
  competence_month    date not null,
  kind                text not null check (kind in ('profit','reimbursement')),
  amount_cents        bigint not null check (amount_cents >= 0),
  paid_at             date,
  notes               text,
  created_by          bigint references public.users(id) on delete set null,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  unique (partner_id, competence_month, kind)
);

create index if not exists finance_distributions_month
  on public.finance_distributions (competence_month desc);
create index if not exists finance_distributions_partner
  on public.finance_distributions (partner_id, competence_month desc);

-- -----------------------------------------------------------------------------
-- 3. Month overrides — historical Guardado = 0, optional manual closing
-- -----------------------------------------------------------------------------
create table if not exists public.finance_months (
  id           uuid primary key default gen_random_uuid(),
  month        date not null unique,
  saved_cents  bigint,
  notes        text,
  closed_at    timestamptz,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

-- -----------------------------------------------------------------------------
-- 4. Expenses — who paid out of pocket + reimbursement settlement
-- -----------------------------------------------------------------------------
alter table public.billing_expenses
  add column if not exists paid_by_partner_id uuid references public.finance_partners(id) on delete set null;
alter table public.billing_expenses
  add column if not exists reimbursed_at date;
alter table public.billing_expenses
  add column if not exists reimbursed_distribution_id uuid references public.finance_distributions(id) on delete set null;

create index if not exists billing_expenses_paid_by_partner
  on public.billing_expenses (paid_by_partner_id)
  where paid_by_partner_id is not null;
create index if not exists billing_expenses_reimbursed
  on public.billing_expenses (reimbursed_at)
  where reimbursed_at is not null;

-- -----------------------------------------------------------------------------
-- 5. Invoices — multi-item totals, approval, delivery and reminders
-- -----------------------------------------------------------------------------
alter table public.billing_invoices
  add column if not exists subtotal_cents bigint;
alter table public.billing_invoices
  add column if not exists discount_cents bigint not null default 0;
alter table public.billing_invoices
  add column if not exists approved_at timestamptz;
alter table public.billing_invoices
  add column if not exists approved_by bigint references public.users(id) on delete set null;
alter table public.billing_invoices
  add column if not exists sent_at timestamptz;
alter table public.billing_invoices
  add column if not exists delivery_status text not null default 'pending'
    check (delivery_status in ('pending','sent','failed'));
alter table public.billing_invoices
  add column if not exists delivery_error text;
alter table public.billing_invoices
  add column if not exists reminder_count integer not null default 0;
alter table public.billing_invoices
  add column if not exists last_reminded_at timestamptz;

create index if not exists billing_invoices_approval
  on public.billing_invoices (status, due_date)
  where status = 'draft';
create index if not exists billing_invoices_delivery
  on public.billing_invoices (delivery_status)
  where delivery_status = 'failed';

-- Existing invoices were already sent; drafts stay pending approval.
update public.billing_invoices
  set subtotal_cents = amount_cents
  where subtotal_cents is null;

update public.billing_invoices
  set delivery_status = 'sent',
      sent_at = coalesce(sent_at, issue_date::timestamptz, created_at),
      approved_at = coalesce(approved_at, issue_date::timestamptz, created_at)
  where status in ('sent','paid','overdue')
    and sent_at is null;

-- -----------------------------------------------------------------------------
-- 6. Prospects — invoice billing block
-- -----------------------------------------------------------------------------
alter table public.prospects
  add column if not exists billing_address text;
alter table public.prospects
  add column if not exists tax_id text;

-- -----------------------------------------------------------------------------
-- 7. Settings — lead time, approval e-mail and editable WhatsApp texts
-- -----------------------------------------------------------------------------
alter table public.billing_settings
  add column if not exists invoice_lead_days smallint not null default 3
    check (invoice_lead_days between 0 and 30);
alter table public.billing_settings
  add column if not exists invoice_approval_email text;
alter table public.billing_settings
  add column if not exists invoice_terms text;
alter table public.billing_settings
  add column if not exists invoice_whatsapp_message text;
alter table public.billing_settings
  add column if not exists dunning_whatsapp_message text;

-- The invoice document renders "#{{ number }}"; INV matches the old template.
update public.billing_settings set invoice_prefix = 'INV' where invoice_prefix = 'VAIF';

-- -----------------------------------------------------------------------------
-- 8. Historical Guardado = 0 for months before the current one.
--    From the current month onward Guardado is derived unless overridden.
-- -----------------------------------------------------------------------------
insert into public.finance_months (month, saved_cents)
select m.month, 0
from (
  select distinct date_trunc('month', period_start)::date as month
    from public.billing_invoices
  union
  select distinct date_trunc('month', paid_at)::date
    from public.billing_payments
  union
  select distinct date_trunc('month', coalesce(paid_at, competence_month))::date
    from public.billing_expenses
) m
where m.month < date_trunc('month', current_date)::date
on conflict (month) do nothing;

-- -----------------------------------------------------------------------------
-- 9. updated_at triggers
-- -----------------------------------------------------------------------------
drop trigger if exists trg_finance_partners_touch on public.finance_partners;
create trigger trg_finance_partners_touch before update on public.finance_partners
  for each row execute function touch_updated_at();

drop trigger if exists trg_finance_distributions_touch on public.finance_distributions;
create trigger trg_finance_distributions_touch before update on public.finance_distributions
  for each row execute function touch_updated_at();

drop trigger if exists trg_finance_months_touch on public.finance_months;
create trigger trg_finance_months_touch before update on public.finance_months
  for each row execute function touch_updated_at();

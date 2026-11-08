-- =============================================================================
-- Finance — invoices, payments and billing settings
--
-- Phase 1: staff records invoices and payments manually. PIX clients get an
-- invoice; card clients (Mercado Pago recurring) get a payment row with no
-- invoice. No credit/installments and no fiscal note (NF-e/NFS-e) here.
--
-- Mirrors app/database/migrations/2026_11_09_000002_create_billing_tables.php
-- for the SQLite test schema. Production applies this SQL directly.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Contract billing fields
-- -----------------------------------------------------------------------------
alter table public.prospect_contracts
  add column if not exists billing_mode text not null default 'pix';
alter table public.prospect_contracts
  add column if not exists payment_method text;
alter table public.prospect_contracts
  add column if not exists mercado_pago_preapproval_id text;
alter table public.prospect_contracts
  add column if not exists mercado_pago_customer_id text;
alter table public.prospect_contracts
  add column if not exists next_charge_at date;
alter table public.prospect_contracts
  add column if not exists notion_page_id text;

do $$
begin
  if not exists (
    select 1 from pg_constraint where conname = 'prospect_contracts_billing_mode_check'
  ) then
    alter table public.prospect_contracts
      add constraint prospect_contracts_billing_mode_check
      check (billing_mode in ('pix','card'));
  end if;

  if not exists (
    select 1 from pg_constraint where conname = 'prospect_contracts_payment_method_check'
  ) then
    alter table public.prospect_contracts
      add constraint prospect_contracts_payment_method_check
      check (payment_method is null or payment_method in ('pix','credit_card','boleto','transfer','cash','other'));
  end if;
end $$;

create index if not exists prospect_contracts_billing_mode on public.prospect_contracts (billing_mode);
create index if not exists prospect_contracts_next_charge on public.prospect_contracts (next_charge_at);
create index if not exists prospect_contracts_notion_page on public.prospect_contracts (notion_page_id);

-- -----------------------------------------------------------------------------
-- 2. Billing settings — one row, editable by staff
-- -----------------------------------------------------------------------------
create table if not exists public.billing_settings (
  id                    smallint primary key default 1 check (id = 1),
  legal_name            text,
  tax_id                text,
  address               text,
  pix_key               text,
  invoice_prefix        text not null default 'VAIF',
  default_due_day       smallint not null default 10 check (default_due_day between 1 and 31),
  payment_instructions  text,
  receipt_footer        text,
  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now()
);

insert into public.billing_settings (id) values (1) on conflict (id) do nothing;

-- -----------------------------------------------------------------------------
-- 3. Invoice number counter — one row per year
-- -----------------------------------------------------------------------------
create table if not exists public.billing_invoice_counters (
  year         smallint primary key,
  last_number  integer not null default 0 check (last_number >= 0)
);

-- -----------------------------------------------------------------------------
-- 4. Invoices — PIX (and manual) cobranças
-- -----------------------------------------------------------------------------
create table if not exists public.billing_invoices (
  id                uuid primary key default gen_random_uuid(),
  number            text not null unique,
  contract_id       uuid references public.prospect_contracts(id) on delete set null,
  prospect_id       uuid not null references public.prospects(id) on delete restrict,
  period_start      date not null,
  period_end        date not null,
  issue_date        date not null default current_date,
  due_date          date not null,
  amount_cents      bigint not null check (amount_cents >= 0),
  paid_cents        bigint not null default 0 check (paid_cents >= 0),
  status            text not null default 'draft'
                    check (status in ('draft','sent','paid','overdue','cancelled')),
  payment_method    text not null default 'pix'
                    check (payment_method in ('pix','boleto','transfer','cash','other')),
  external_ref      text,
  pix_copy_paste    text,
  pix_qr_code       text,
  notes             text,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  unique (contract_id, period_start)
);

create index if not exists billing_invoices_prospect on public.billing_invoices (prospect_id, created_at desc);
create index if not exists billing_invoices_contract on public.billing_invoices (contract_id);
create index if not exists billing_invoices_status on public.billing_invoices (status);
create index if not exists billing_invoices_due on public.billing_invoices (due_date);
create index if not exists billing_invoices_period on public.billing_invoices (period_start desc);

-- -----------------------------------------------------------------------------
-- 5. Invoice items
-- -----------------------------------------------------------------------------
create table if not exists public.billing_invoice_items (
  id                  bigserial primary key,
  invoice_id          uuid not null references public.billing_invoices(id) on delete cascade,
  description         text not null,
  quantity            numeric(10,2) not null default 1 check (quantity > 0),
  unit_amount_cents   bigint not null check (unit_amount_cents >= 0),
  total_cents         bigint not null check (total_cents >= 0),
  sort_order          integer not null default 0,
  created_at          timestamptz not null default now()
);

create index if not exists billing_invoice_items_invoice on public.billing_invoice_items (invoice_id, sort_order);

-- -----------------------------------------------------------------------------
-- 6. Payments — invoice-linked (PIX) or standalone (card)
-- -----------------------------------------------------------------------------
create table if not exists public.billing_payments (
  id             uuid primary key default gen_random_uuid(),
  invoice_id     uuid references public.billing_invoices(id) on delete set null,
  contract_id    uuid references public.prospect_contracts(id) on delete set null,
  prospect_id    uuid not null references public.prospects(id) on delete cascade,
  amount_cents   bigint not null check (amount_cents > 0),
  method         text not null
                 check (method in ('pix','credit_card','boleto','transfer','cash','other')),
  paid_at        date not null,
  reference      text,
  external_ref   text,
  source         text not null default 'manual'
                 check (source in ('manual','mercado_pago','n8n')),
  notes          text,
  recorded_by    bigint references public.users(id) on delete set null,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now()
);

create index if not exists billing_payments_invoice on public.billing_payments (invoice_id);
create index if not exists billing_payments_contract on public.billing_payments (contract_id);
create index if not exists billing_payments_prospect on public.billing_payments (prospect_id, paid_at desc);
create index if not exists billing_payments_paid on public.billing_payments (paid_at desc);
create unique index if not exists billing_payments_external_ref_key
  on public.billing_payments (external_ref)
  where external_ref is not null and source in ('mercado_pago','n8n');

-- -----------------------------------------------------------------------------
-- 7. updated_at triggers
-- -----------------------------------------------------------------------------
drop trigger if exists trg_billing_settings_touch on public.billing_settings;
create trigger trg_billing_settings_touch before update on public.billing_settings
  for each row execute function touch_updated_at();

drop trigger if exists trg_billing_invoices_touch on public.billing_invoices;
create trigger trg_billing_invoices_touch before update on public.billing_invoices
  for each row execute function touch_updated_at();

drop trigger if exists trg_billing_payments_touch on public.billing_payments;
create trigger trg_billing_payments_touch before update on public.billing_payments
  for each row execute function touch_updated_at();

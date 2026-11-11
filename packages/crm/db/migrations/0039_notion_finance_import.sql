-- =============================================================================
-- Notion finance import support
--
-- Historical Entradas → invoices + payments (including card, as a historical
-- exception) and Saídas → billing_expenses. Idempotency uses external_ref for
-- invoices/payments and notion_page_id for expenses.
-- =============================================================================

-- Payment method: historical Entradas may carry a card payment.
alter table public.billing_invoices
  drop constraint if exists billing_invoices_payment_method_check;
alter table public.billing_invoices
  add constraint billing_invoices_payment_method_check
  check (payment_method in ('pix','credit_card','boleto','transfer','cash','other'));

-- Payment source: the import is a first-class source and must be unique by
-- external_ref like the n8n/Mercado Pago sources.
alter table public.billing_payments
  drop constraint if exists billing_payments_source_check;
alter table public.billing_payments
  add constraint billing_payments_source_check
  check (source in ('manual','mercado_pago','n8n','notion'));

drop index if exists public.billing_payments_external_ref_key;
create unique index if not exists billing_payments_external_ref_key
  on public.billing_payments (external_ref)
  where external_ref is not null and source in ('mercado_pago','n8n','notion');

-- Expenses did not have an external reference. Notion page ID is the stable
-- idempotency key for the Saídas import.
alter table public.billing_expenses
  add column if not exists notion_page_id text;

create unique index if not exists billing_expenses_notion_page_key
  on public.billing_expenses (notion_page_id)
  where notion_page_id is not null;

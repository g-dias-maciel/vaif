# Financeiro (cobranças e pagamentos)

The finance layer is **staff-only**. It tracks invoices, payments and overdue
balances; it does not issue NF-e/NFS-e, extend credit or create installments.

## Two payment paths

| Client pays by | Contract `billing_mode` | CRM behaviour |
|---|---|---|
| Credit card (Mercado Pago recurring) | `card` | No invoice. Each charge is a `billing_payments` row with `invoice_id = null`. |
| PIX | `pix` | A `billing_invoices` row is generated; payment links to it. |

No card data is stored. Only the Mercado Pago `preapproval_id` and
`customer_id` references live on the contract.

## Linking a contract to an active client

Imported Notion clients arrive as active prospects **without a contract** on
purpose. Go to **Financeiro → Clientes e contratos**, find the client and click
**Vincular contrato**. The contract sets:

- plan (`mensal`, `semestral`, `twelve_months`, `full_service`, `members_area`);
- monthly and total value;
- start/end and billing day;
- `billing_mode` (`pix` or `card`) and payment method;
- next PIX invoice date or next card charge date;
- optional Mercado Pago and Notion references.

Linking a contract does **not** create a login, invite or onboarding task.

## Commands

```bash
# Generate every PIX invoice that is due (idempotent)
php artisan finance:generate-invoices

# Preview without writing
php artisan finance:generate-invoices --dry-run

# One contract
php artisan finance:generate-invoices --contract=<uuid>

# Mark unpaid invoices past due as overdue
php artisan finance:mark-overdue

# Preview
php artisan finance:mark-overdue --dry-run
```

Run both daily (Laravel scheduler or the Coolify cron). `finance:generate-invoices`
skips card contracts and advances `next_invoice_at` by one month; it caps a
single run at 24 catch-up invoices per contract.

## Statuses

| Invoice status | Meaning |
|---|---|
| `draft` | Created but not sent |
| `sent` | Open, waiting for payment |
| `paid` | `paid_cents >= amount_cents` |
| `overdue` | Open and past `due_date` |
| `cancelled` | Cancelled; cannot receive payments |

Partial payments are allowed. An invoice becomes `paid` when the sum of its
payments reaches the amount; the balance is always `amount_cents - paid_cents`.

## Card charges

Record a card charge at **Financeiro → Pagamentos**: choose the card contract,
amount, date and method `Cartão de crédito`. It is revenue with no invoice and
advances `next_charge_at` by one month when the paid date reaches the current
next charge date.

For Mercado Pago automation later, call `PaymentRecorder` (or the payments
endpoint) with `source = mercado_pago` and a unique `external_ref` (the MP
payment ID). The partial unique index prevents duplicate webhook rows.

## Receipts

Every invoice has a printable **Recibo** (HTML, print dialog). It uses the
issuer data and PIX key from **Financeiro → Configuração financeira**. It is a
simple payment receipt, not a fiscal document.

## Access

All `/finance/*` routes use `role:staff`. To add a dedicated finance role later,
add the role to the user model and change the route group to
`role:staff,finance`; the data model does not need to change.

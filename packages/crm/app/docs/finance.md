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

# Clone monthly recurring expenses up to next month
php artisan finance:generate-recurring-expenses

# Or up to a specific month
php artisan finance:generate-recurring-expenses --through=2027-01
```

The container runs all three daily: invoices at 06:00, overdue checks at 06:10
and recurring expenses at 06:20. `finance:generate-invoices` skips card
contracts and advances `next_invoice_at` by one month; it caps a single run at
24 catch-up invoices per contract.

## Expenses (saídas)

Every cost is a `billing_expenses` row assigned to a **competence month**:

- description, vendor, category;
- amount;
- competence month and due date;
- status `pending` / `paid` / `cancelled`;
- payment method and optional notes;
- recurrence `one_off` / `monthly`.

Categories: anúncios, ferramentas, salários/pró-labore, freelancers, impostos,
estrutura, outros.

A pending expense past its due date is shown as overdue. **Financeiro →
Despesas** shows the selected month’s total, paid, pending and overdue, and
lets staff mark an expense as paid or cancel it.

### Recurring expenses

A monthly expense is the template. The CLI clones it into every missing month
up to the target month (default: next month):

```bash
php artisan finance:generate-recurring-expenses
php artisan finance:generate-recurring-expenses --through=2027-01
php artisan finance:generate-recurring-expenses --dry-run
```

The same action is available as **Gerar recorrentes** on the Expenses page.
Clones are idempotent by `(recurrence_key, competence_month)`. If the latest
expense in a series is cancelled, the series stops; create a new monthly
expense to restart it.

## Monthly overview

**Financeiro → Visão mensal** shows the selected month and the last 12 months:

| Column | Meaning |
|---|---|
| Cobrado | invoices whose `period_start` is in the month (not cancelled) |
| Recebido | payments whose `paid_at` is in the month (PIX + card) |
| Despesas | expenses whose `competence_month` is the month |
| Desp. pagas | expenses whose `paid_at` is in the month |
| Lucro caixa | Recebido − Despesas pagas |
| A pagar | pending expenses for the month |
| Resultado | Cobrado − Despesas do mês |
| MRR | active contracts at month end |

The dashboard also shows the current month’s expenses, payables, cash profit
and a “Contas a pagar nos próximos 30 dias” list.

Both commands run daily: invoices at 06:00, overdue checks at 06:10 and
recurring expenses at 06:20 (America/Sao_Paulo).

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

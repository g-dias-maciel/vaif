# Financeiro (cobranças, despesas e distribuição)

The finance layer is **staff-only**. It tracks invoices, receipts, expenses,
partner profit distribution and reimbursements. It does not issue NF-e/NFS-e,
extend credit or create installments. Mercado Pago is not integrated: card
payments are recorded as receipts (the recurring payment link is sent outside
the CRM).

## Home: annual overview

**Financeiro** (`/finance`) is the Notion-style home:

| Column | Meaning |
|---|---|
| A receber | open invoice balance whose `period_start` is in the month |
| Recebido | payments whose `paid_at` is in the month (PIX + card) |
| Saídas | expenses whose `paid_at` is in the month (cash) |
| Lucro líquido | Recebido − Saídas |
| Guardado | Lucro − profit distributions (historical months are seeded as 0) |
| ROI | Lucro ÷ Saídas pagas |

The year selector shows the 12 months and a year total. The alert strip shows
invoices waiting for approval, overdue invoices (with **Cobrar todos**), active
clients without a contract and overdue expenses.

Clicking a month opens `/finance/months?month=YYYY-MM` with three tabs:

- **Entradas** — invoices plus card/manual receipts that have no invoice.
- **Saídas** — expenses, “Pago por”, status and actions.
- **Distribuição** — partner profit shares and reimbursements.

## Two payment paths

| Client pays by | Contract `billing_mode` | CRM behaviour |
|---|---|---|
| Credit card (recurring link) | `card` | No invoice. Each charge is a `billing_payments` row with `invoice_id = null`. |
| PIX | `pix` | A `billing_invoices` row is generated, approved, sent and paid. |

No card data is stored. The old Mercado Pago ID fields remain in the database
but are hidden from the contract form.

## Invoice lifecycle

1. `finance:generate-invoices` finds PIX contracts where
   `next_invoice_at − invoice_lead_days <= today` (default **3 days**).
2. The invoice is created as **`draft`** with items, subtotal, discount and the
   due date on the contract's `billing_day`.
3. A digest e-mail goes to `billing_settings.invoice_approval_email` (or
   `VAIF_ADMIN_EMAIL` when empty). The e-mail links to each invoice.
4. Staff reviews/edits the invoice and clicks **Aprovar e enviar**.
5. The CRM renders the PDF from `finance/invoices/pdf.blade.php` (the old Google
   Docs `invoice_template` layout), stores it under `storage/app/invoices` and
   creates a signed 30-day public link.
6. The invoice is sent over WhatsApp through the company WAHA session
   (`WAHA_FINANCE_SESSION`, default `default`).
7. `delivery_status` records `pending`/`sent`/`failed`; failures show a retry
   action on the invoice page.
8. **Cobrar atraso** resends the PDF with the dunning message. It increments
   `reminder_count` and `last_reminded_at`. The annual home can bulk-charge all
   overdue invoices.

Editing a sent invoice resets `delivery_status` to `pending` and clears
`sent_at`, because the client's PDF no longer matches the new version. Use
**Reenviar fatura**.

Drafts never become “atrasada”; only `sent` invoices are marked overdue by the
daily command.

### Invoice number

New invoices use the prefix from **Configuração financeira** (default `INV`),
e.g. `INV-2026-0001`. The document renders `#INV-2026-0001`.

### PDF and WhatsApp

- PDF: `App\Services\Finance\InvoicePdfRenderer` (Dompdf).
- Sending: `App\Services\Finance\InvoiceNotifier` → `WahaService`.
- Editable messages and terms live in **Configuração financeira**:
  - `invoice_terms` (late fee / service pause text)
  - `invoice_whatsapp_message`
  - `dunning_whatsapp_message`
  - variables: `{primeiro_nome}`, `{cliente}`, `{numero}`, `{periodo}`,
    `{vencimento}`, `{valor}`, `{link}`, `{notas}`, `{pix}`.

The old n8n **Create Invoice** workflow is no longer used. Google Docs/Drive are
also out of the path.

## Commands and schedule

```bash
# Generate due PIX invoices (drafts) and e-mail the approval digest
php artisan finance:generate-invoices
php artisan finance:generate-invoices --dry-run
php artisan finance:generate-invoices --contract=<uuid>
php artisan finance:generate-invoices --no-mail

# Mark sent invoices past due as overdue
php artisan finance:mark-overdue

# Clone monthly recurring expenses up to next month
php artisan finance:generate-recurring-expenses
php artisan finance:generate-recurring-expenses --through=2027-01

# One-off: create the initial partners (Daniel/Gabriel, 50% each)
php artisan finance:setup-partners
```

The container runs the three daily commands at 06:00, 06:10 and 06:20
(America/Sao_Paulo).

## Expenses (saídas)

Every cost is a `billing_expenses` row assigned to a competence month:

- description, vendor, category, amount;
- competence month and due date;
- status `pending` / `paid` / `cancelled`;
- payment method and optional notes;
- recurrence `one_off` / `monthly`;
- **Pago por**: `VAIF` (company account) or a partner.

When **Pago por** is a partner, the expense is a payable to that partner. The
Distribuição tab lists unreimbursed partner-paid expenses across months; saving
the distribution marks them as reimbursed (`reimbursed_at` +
`reimbursed_distribution_id`) so they cannot be paid twice.

Categories: anúncios, ferramentas, salários/pró-labore, freelancers, impostos,
estrutura, outros.

The monthly **Saídas** tab has:

- **Repetir mês anterior** — copies the previous month's non-cancelled expenses
  as pending; idempotent by `copied_from_id + competence_month`.
- **Gerar recorrentes** — clones `monthly` expenses up to next month.

## Partner distribution

Two partners share the profit (`finance_partners.share_bps`, 5000 = 50%).

The **Distribuição** tab, for the selected month:

- prefills each partner's profit as `max(lucro, 0) × share`;
- lists unreimbursed expenses paid by that partner;
- shows `total = lucro share + reimbursement`;
- saves two rows in `finance_distributions` per partner when applicable:
  `kind = profit` and `kind = reimbursement`.

**Guardado** = `lucro líquido − profit distributions`. Reimbursements do not
reduce Guardado again: they settle an expense that was already counted in
Saídas. Historical months before the CRM cutover are seeded with
`finance_months.saved_cents = 0` because Notion's Pagamentos were not imported.

## Importing the Notion history

The normalized Notion Financeiro export can be imported with:

```bash
php artisan finance:import-notion /path/to/notion-finance.json --dry-run
php artisan finance:import-notion /path/to/notion-finance.json --report=/tmp/report.json
```

Mapping:

- **Entradas** → one invoice per row. Paid rows also get a linked payment.
  Card entries are imported as historical invoices too, so billed totals match
  Notion. Zero/missing values are skipped.
- **Saídas** → paid expenses with the competence month from the Notion `Mes`
  relation. Categories are inferred from `Name`. **Pago por** (`VAIF`, Daniel,
  Gabriel) sets `paid_by_partner_id` (VAIF = null). Historical partner-paid
  expenses are marked as already reimbursed so they do not create a backlog.
- **Clients** → matched by Notion page id, phone, e-mail, exact name, then
  Instagram. Unmatched clients become archived historical prospects with a
  placeholder phone and `sem_telefone_notion` flag.
- **Pagamentos** → not imported. Distributions start from the CRM cutover.

Idempotency:

- invoices: `external_ref = notion:entrada:<page_id>`
- payments: `external_ref = notion:entrada:<page_id>:payment`
- expenses: `notion_page_id`

## Statuses

| Invoice status | Meaning |
|---|---|
| `draft` | Created, waiting for approval |
| `sent` | Approved and delivered (or delivery failed) |
| `paid` | `paid_cents >= amount_cents` |
| `overdue` | Sent and past `due_date` |
| `cancelled` | Cancelled; cannot receive payments |

Partial payments are allowed. An invoice becomes `paid` when the sum of its
payments reaches the amount; the balance is always `amount_cents - paid_cents`.
Draft invoices cannot receive payments until approved.

## Access

All `/finance/*` routes use `role:staff`, except the signed public PDF route
(`/finance/invoices/{invoice}/pdf-public`), which requires a valid signature
and no login.

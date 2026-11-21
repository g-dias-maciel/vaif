<?php

namespace App\Services\Finance;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One income list for the month: PIX invoices plus card/manual receipts that
 * have no invoice. Invoice-linked payments are not repeated — the invoice row
 * already carries its paid/balance columns.
 */
class FinanceLedger
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function entriesForMonth(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $invoices = BillingInvoice::query()
            ->with(['prospect', 'items'])
            ->whereBetween('period_start', [$start, $end])
            ->where('status', '!=', BillingInvoice::STATUS_CANCELLED)
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->get()
            ->map(function (BillingInvoice $invoice): array {
                $item = $invoice->items->first();

                return [
                    'type' => 'invoice',
                    'date' => $invoice->due_date ?? $invoice->issue_date,
                    'client' => $invoice->prospect?->name ?? '—',
                    'description' => $item?->description ?: 'Cobrança '.$invoice->number,
                    'reference' => $invoice->number,
                    'method' => $invoice->paymentMethodLabel(),
                    'status' => $invoice->statusLabel(),
                    'tone' => $invoice->statusTone(),
                    'amount_cents' => $invoice->amount_cents,
                    'paid_cents' => $invoice->paid_cents,
                    'balance_cents' => $invoice->balanceCents(),
                    'invoice' => $invoice,
                    'payment' => null,
                    'is_draft' => $invoice->isDraft(),
                    'is_overdue' => $invoice->isOverdue(),
                    'can_remind' => ! $invoice->isPaid()
                        && ! $invoice->isCancelled()
                        && ! $invoice->isDraft()
                        && $invoice->isOverdue(),
                ];
            });

        $payments = BillingPayment::query()
            ->with(['prospect', 'contract'])
            ->whereNull('invoice_id')
            ->whereBetween('paid_at', [$start, $end])
            ->orderBy('paid_at')
            ->get()
            ->map(function (BillingPayment $payment): array {
                return [
                    'type' => 'payment',
                    'date' => $payment->paid_at,
                    'client' => $payment->prospect?->name ?? '—',
                    'description' => 'Recebimento — '.$payment->methodLabel(),
                    'reference' => $payment->external_ref ?: ($payment->reference ?: '—'),
                    'method' => $payment->methodLabel(),
                    'status' => 'Recebido',
                    'tone' => 'ok',
                    'amount_cents' => $payment->amount_cents,
                    'paid_cents' => $payment->amount_cents,
                    'balance_cents' => 0,
                    'invoice' => null,
                    'payment' => $payment,
                    'is_draft' => false,
                    'is_overdue' => false,
                    'can_remind' => false,
                ];
            });

        return $invoices
            ->concat($payments)
            ->sortBy(fn (array $entry) => $entry['date']?->timestamp ?? 0)
            ->values();
    }
}

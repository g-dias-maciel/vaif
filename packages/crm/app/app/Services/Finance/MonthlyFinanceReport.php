<?php

namespace App\Services\Finance;

use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\ProspectContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Month-over-month finance report.
 *
 * Revenue is cash received (billing_payments.paid_at); the invoice side is also
 * shown as "cobrado". Expenses use competence_month for the month view and
 * paid_at for the cash view; pending expenses are the payables.
 */
class MonthlyFinanceReport
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $today = now()->startOfDay();

        $invoiced = (int) BillingInvoice::query()
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingInvoice::STATUS_CANCELLED)
            ->sum('amount_cents');

        $received = (int) BillingPayment::query()
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->sum('amount_cents');

        $receivedCard = (int) BillingPayment::query()
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->where('method', BillingPayment::METHOD_CREDIT_CARD)
            ->sum('amount_cents');

        $receivedPix = (int) BillingPayment::query()
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->where('method', BillingPayment::METHOD_PIX)
            ->sum('amount_cents');

        $expenses = (int) BillingExpense::query()
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
            ->sum('amount_cents');

        $expensesPaid = (int) BillingExpense::query()
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
            ->sum('amount_cents');

        $payables = (int) BillingExpense::query()
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->where('status', BillingExpense::STATUS_PENDING)
            ->sum('amount_cents');

        $overdueCutoff = $end->copy()->lt($today) ? $end : ($start->copy()->gt($today) ? null : $today);

        $overduePayables = $overdueCutoff === null
            ? 0
            : (int) BillingExpense::query()
                ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
                ->where('status', BillingExpense::STATUS_PENDING)
                ->whereDate('due_date', '<', $overdueCutoff->toDateString())
                ->sum('amount_cents');

        $receivable = (int) BillingInvoice::query()
            ->open()
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('coalesce(sum(amount_cents - paid_cents), 0) as total')
            ->value('total');

        $mrr = (int) ProspectContract::query()
            ->whereIn('status', [
                ProspectContract::STATUS_ACTIVE,
                ProspectContract::STATUS_ENDED,
                ProspectContract::STATUS_CHURNED,
            ])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->where(function ($query) use ($end): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $end->toDateString());
            })
            ->sum('monthly_value_cents');

        $contracts = (int) ProspectContract::query()
            ->whereIn('status', [
                ProspectContract::STATUS_ACTIVE,
                ProspectContract::STATUS_ENDED,
                ProspectContract::STATUS_CHURNED,
            ])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->where(function ($query) use ($end): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $end->toDateString());
            })
            ->count();

        return [
            'month' => $start,
            'label' => $start->format('m/Y'),
            'invoiced_cents' => $invoiced,
            'received_cents' => $received,
            'received_card_cents' => $receivedCard,
            'received_pix_cents' => $receivedPix,
            'expenses_cents' => $expenses,
            'expenses_paid_cents' => $expensesPaid,
            'payables_cents' => $payables,
            'overdue_payables_cents' => $overduePayables,
            'receivable_cents' => $receivable,
            'profit_cash_cents' => $received - $expensesPaid,
            'profit_accrual_cents' => $invoiced - $expenses,
            'mrr_cents' => $mrr,
            'contracts_count' => $contracts,
            'invoices_count' => (int) BillingInvoice::query()
                ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
                ->where('status', '!=', BillingInvoice::STATUS_CANCELLED)
                ->count(),
            'expenses_count' => (int) BillingExpense::query()
                ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
                ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
                ->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function months(int $count = 12): Collection
    {
        $start = now()->startOfMonth()->subMonths(max(0, $count - 1));

        return collect(range(0, max(0, $count - 1)))
            ->map(fn (int $offset) => $this->summary($start->copy()->addMonths($offset)));
    }
}

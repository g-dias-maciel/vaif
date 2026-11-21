<?php

namespace App\Services\Finance;

use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\FinanceDistribution;
use App\Models\FinanceMonth;
use App\Models\ProspectContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Month-over-month finance report.
 *
 * All table data for the requested range is loaded once and grouped in PHP, so
 * a 12-month overview is a handful of queries instead of ~13 per month.
 *
 * Metrics are cash-based: Recebido uses payments.paid_at; Saídas uses
 * expenses.paid_at. Competence-month figures (invoiced, payables) are kept for
 * the month cards and the receivables side.
 */
class MonthlyFinanceReport
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Carbon $month): array
    {
        return $this->summaries($month, $month)->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function overviewForYear(int $year): Collection
    {
        return $this->summaries(
            Carbon::create($year, 1, 1)->startOfMonth(),
            Carbon::create($year, 12, 1)->startOfMonth(),
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function months(int $count = 12): Collection
    {
        $count = max(1, $count);
        $start = now()->startOfMonth()->subMonths($count - 1);

        return $this->summaries($start, now()->startOfMonth());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function summaries(Carbon $from, Carbon $to): Collection
    {
        $from = $from->copy()->startOfMonth();
        $to = $to->copy()->startOfMonth();
        $end = $to->copy()->endOfMonth();
        $today = now()->startOfDay();

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = [
                'key' => $key,
                'month' => $cursor->copy()->startOfMonth(),
                'label' => $cursor->format('m/Y'),
                'invoiced_cents' => 0,
                'received_cents' => 0,
                'received_card_cents' => 0,
                'received_pix_cents' => 0,
                'expenses_cents' => 0,
                'expenses_paid_cents' => 0,
                'payables_cents' => 0,
                'overdue_payables_cents' => 0,
                'receivable_cents' => 0,
                'distributed_profit_cents' => 0,
                'distributed_reimbursement_cents' => 0,
                'profit_cents' => 0,
                'saved_cents' => 0,
                'roi_percent' => null,
                'mrr_cents' => 0,
                'contracts_count' => 0,
                'invoices_count' => 0,
                'expenses_count' => 0,
                'awaiting_approval_count' => 0,
                'awaiting_approval_cents' => 0,
                'saved_override' => null,
            ];
        }

        $rangeStart = $from->toDateString();
        $rangeEnd = $end->toDateString();

        // ---- Invoices -----------------------------------------------------
        BillingInvoice::query()
            ->whereBetween('period_start', [$rangeStart, $rangeEnd])
            ->get(['period_start', 'amount_cents', 'paid_cents', 'status'])
            ->each(function (BillingInvoice $invoice) use (&$buckets): void {
                if ($invoice->status === BillingInvoice::STATUS_CANCELLED) {
                    return;
                }

                $key = $invoice->period_start?->format('Y-m');

                if ($key === null || ! isset($buckets[$key])) {
                    return;
                }

                $buckets[$key]['invoiced_cents'] += $invoice->amount_cents;
                $buckets[$key]['invoices_count']++;

                if (in_array($invoice->status, [
                    BillingInvoice::STATUS_DRAFT,
                    BillingInvoice::STATUS_SENT,
                    BillingInvoice::STATUS_OVERDUE,
                ], true)) {
                    $buckets[$key]['receivable_cents'] += max(0, $invoice->amount_cents - $invoice->paid_cents);
                }

                if ($invoice->status === BillingInvoice::STATUS_DRAFT) {
                    $buckets[$key]['awaiting_approval_count']++;
                    $buckets[$key]['awaiting_approval_cents'] += $invoice->amount_cents;
                }
            });

        // ---- Payments -----------------------------------------------------
        BillingPayment::query()
            ->whereBetween('paid_at', [$rangeStart, $rangeEnd])
            ->get(['paid_at', 'amount_cents', 'method'])
            ->each(function (BillingPayment $payment) use (&$buckets): void {
                $key = $payment->paid_at?->format('Y-m');

                if ($key === null || ! isset($buckets[$key])) {
                    return;
                }

                $buckets[$key]['received_cents'] += $payment->amount_cents;

                if ($payment->method === BillingPayment::METHOD_CREDIT_CARD) {
                    $buckets[$key]['received_card_cents'] += $payment->amount_cents;
                } elseif ($payment->method === BillingPayment::METHOD_PIX) {
                    $buckets[$key]['received_pix_cents'] += $payment->amount_cents;
                }
            });

        // ---- Expenses -----------------------------------------------------
        /** @var array<string, array<int, array{due: ?Carbon, amount: int}>> $pendingByMonth */
        $pendingByMonth = [];

        BillingExpense::query()
            ->where(function ($query) use ($rangeStart, $rangeEnd): void {
                $query->whereBetween('competence_month', [$rangeStart, $rangeEnd])
                    ->orWhereBetween('paid_at', [$rangeStart, $rangeEnd]);
            })
            ->get(['competence_month', 'due_date', 'paid_at', 'amount_cents', 'status'])
            ->each(function (BillingExpense $expense) use (&$buckets, &$pendingByMonth): void {
                if ($expense->status === BillingExpense::STATUS_CANCELLED) {
                    return;
                }

                $competenceKey = $expense->competence_month?->format('Y-m');

                if ($competenceKey !== null && isset($buckets[$competenceKey])) {
                    $buckets[$competenceKey]['expenses_cents'] += $expense->amount_cents;
                    $buckets[$competenceKey]['expenses_count']++;

                    if ($expense->status === BillingExpense::STATUS_PENDING) {
                        $buckets[$competenceKey]['payables_cents'] += $expense->amount_cents;
                        $pendingByMonth[$competenceKey][] = [
                            'due' => $expense->due_date,
                            'amount' => $expense->amount_cents,
                        ];
                    }
                }

                $paidKey = $expense->paid_at?->format('Y-m');

                if ($paidKey !== null && isset($buckets[$paidKey])) {
                    $buckets[$paidKey]['expenses_paid_cents'] += $expense->amount_cents;
                }
            });

        foreach ($buckets as $key => $bucket) {
            $monthStart = $bucket['month']->copy()->startOfMonth();
            $monthEnd = $bucket['month']->copy()->endOfMonth();

            $cutoff = $monthEnd->lt($today)
                ? $monthEnd
                : ($monthStart->gt($today) ? null : $today);

            if ($cutoff === null) {
                continue;
            }

            foreach ($pendingByMonth[$key] ?? [] as $pending) {
                if ($pending['due'] !== null && $pending['due']->lt($cutoff)) {
                    $buckets[$key]['overdue_payables_cents'] += $pending['amount'];
                }
            }
        }

        // ---- Distributions ------------------------------------------------
        FinanceDistribution::query()
            ->whereBetween('competence_month', [$rangeStart, $rangeEnd])
            ->get(['competence_month', 'kind', 'amount_cents'])
            ->each(function (FinanceDistribution $distribution) use (&$buckets): void {
                $key = $distribution->competence_month?->format('Y-m');

                if ($key === null || ! isset($buckets[$key])) {
                    return;
                }

                if ($distribution->kind === FinanceDistribution::KIND_PROFIT) {
                    $buckets[$key]['distributed_profit_cents'] += $distribution->amount_cents;
                } else {
                    $buckets[$key]['distributed_reimbursement_cents'] += $distribution->amount_cents;
                }
            });

        // ---- Saved override ----------------------------------------------
        FinanceMonth::query()
            ->whereBetween('month', [$rangeStart, $rangeEnd])
            ->get(['month', 'saved_cents'])
            ->each(function (FinanceMonth $month) use (&$buckets): void {
                $key = $month->month?->format('Y-m');

                if ($key === null || ! isset($buckets[$key]) || $month->saved_cents === null) {
                    return;
                }

                $buckets[$key]['saved_override'] = $month->saved_cents;
            });

        // ---- Contracts / MRR ----------------------------------------------
        $contracts = ProspectContract::query()
            ->whereIn('status', [
                ProspectContract::STATUS_ACTIVE,
                ProspectContract::STATUS_ENDED,
                ProspectContract::STATUS_CHURNED,
            ])
            ->whereDate('start_date', '<=', $rangeEnd)
            ->where(function ($query) use ($rangeStart): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $rangeStart);
            })
            ->get(['start_date', 'end_date', 'monthly_value_cents']);

        foreach ($buckets as $key => $bucket) {
            $monthEnd = $bucket['month']->copy()->endOfMonth();

            foreach ($contracts as $contract) {
                if ($contract->start_date !== null && $contract->start_date->gt($monthEnd)) {
                    continue;
                }

                if ($contract->end_date !== null && $contract->end_date->lt($monthEnd)) {
                    continue;
                }

                $buckets[$key]['mrr_cents'] += $contract->monthly_value_cents;
                $buckets[$key]['contracts_count']++;
            }
        }

        // ---- Finalize -----------------------------------------------------
        foreach ($buckets as $key => $bucket) {
            $profit = $bucket['received_cents'] - $bucket['expenses_paid_cents'];
            $saved = $bucket['saved_override'] ?? ($profit - $bucket['distributed_profit_cents']);
            $roi = $bucket['expenses_paid_cents'] > 0
                ? round($profit / $bucket['expenses_paid_cents'] * 100, 2)
                : null;

            $buckets[$key]['profit_cents'] = $profit;
            $buckets[$key]['saved_cents'] = $saved;
            $buckets[$key]['roi_percent'] = $roi;
        }

        return collect(array_values($buckets));
    }
}

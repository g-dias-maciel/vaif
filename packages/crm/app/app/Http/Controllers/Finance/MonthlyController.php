<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingExpense;
use App\Models\FinanceDistribution;
use App\Models\FinanceMonth;
use App\Models\FinancePartner;
use App\Services\Finance\FinanceLedger;
use App\Services\Finance\MonthlyFinanceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

class MonthlyController extends Controller
{
    public function index(Request $request, MonthlyFinanceReport $report, FinanceLedger $ledger)
    {
        $value = (string) $request->input('month', now()->format('Y-m'));

        try {
            $month = Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (Throwable) {
            $month = now()->startOfMonth();
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $expenses = BillingExpense::query()
            ->with('payer')
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
            ->orderBy('due_date')
            ->orderBy('description')
            ->get();

        $partners = FinancePartner::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $distributions = FinanceDistribution::query()
            ->with('partner')
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->get();

        $reimbursementIds = $distributions
            ->where('kind', FinanceDistribution::KIND_REIMBURSEMENT)
            ->pluck('id')
            ->all();

        $unreimbursed = BillingExpense::query()
            ->where('status', BillingExpense::STATUS_PAID)
            ->whereNotNull('paid_by_partner_id')
            ->where(function ($query) use ($reimbursementIds): void {
                $query->whereNull('reimbursed_at');

                if ($reimbursementIds !== []) {
                    $query->orWhereIn('reimbursed_distribution_id', $reimbursementIds);
                }
            })
            ->orderBy('paid_at')
            ->orderBy('description')
            ->get()
            ->groupBy('paid_by_partner_id');

        $savedOverride = FinanceMonth::savedFor($start);

        return view('finance.month', [
            'month' => $month,
            'summary' => $report->summary($month),
            'entries' => $ledger->entriesForMonth($month),
            'expenses' => $expenses,
            'partners' => $partners,
            'distributions' => $distributions,
            'unreimbursed' => $unreimbursed,
            'savedOverride' => $savedOverride,
        ]);
    }
}

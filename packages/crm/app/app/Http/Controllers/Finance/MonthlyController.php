<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Services\Finance\MonthlyFinanceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

class MonthlyController extends Controller
{
    public function index(Request $request, MonthlyFinanceReport $report)
    {
        $value = (string) $request->input('month', now()->format('Y-m'));

        try {
            $month = Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (Throwable) {
            $month = now()->startOfMonth();
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $invoices = BillingInvoice::query()
            ->with('prospect')
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingInvoice::STATUS_CANCELLED)
            ->orderBy('period_start')
            ->orderBy('number')
            ->get();

        $expenses = BillingExpense::query()
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
            ->orderBy('due_date')
            ->orderBy('description')
            ->get();

        return view('finance.months', [
            'month' => $month,
            'summary' => $report->summary($month),
            'months' => $report->months(12),
            'invoices' => $invoices,
            'expenses' => $expenses,
        ]);
    }
}

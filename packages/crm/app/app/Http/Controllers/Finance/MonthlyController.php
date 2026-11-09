<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
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

        return view('finance.months', [
            'month' => $month,
            'summary' => $report->summary($month),
            'months' => $report->months(12),
        ]);
    }
}

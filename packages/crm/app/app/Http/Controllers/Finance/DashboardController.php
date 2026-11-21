<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Prospect;
use App\Models\ProspectContract;
use App\Services\Finance\MonthlyFinanceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Staff-only finance home: the year overview (the Notion "Financeiro" page)
 * plus the alerts that need action.
 */
class DashboardController extends Controller
{
    public function index(Request $request, MonthlyFinanceReport $report)
    {
        $year = (int) $request->input('year', now()->year);

        if ($year < 2000 || $year > 2100) {
            $year = now()->year;
        }

        $overview = $report->overviewForYear($year);
        $current = $report->summary(now()->startOfMonth());

        $awaiting = BillingInvoice::query()
            ->awaitingApproval()
            ->orderBy('due_date')
            ->orderBy('number')
            ->get();

        $overdue = BillingInvoice::query()
            ->overdue()
            ->orderBy('due_date')
            ->get();

        $withoutContract = Prospect::query()
            ->where('status', Prospect::STATUS_ACTIVE)
            ->whereDoesntHave('contracts', fn ($q) => $q->where('status', ProspectContract::STATUS_ACTIVE))
            ->count();

        $overdueExpenses = BillingExpense::query()
            ->overdue()
            ->count();

        $earliest = collect([
            BillingInvoice::min('period_start'),
            BillingPayment::min('paid_at'),
            BillingExpense::min('competence_month'),
            ProspectContract::min('start_date'),
        ])
            ->filter()
            ->map(fn ($date) => (int) Carbon::parse((string) $date)->format('Y'))
            ->min() ?? now()->year;

        $years = range(now()->year, min($earliest, now()->year));

        return view('finance.overview', [
            'year' => $year,
            'years' => $years,
            'overview' => $overview,
            'current' => $current,
            'awaiting' => $awaiting,
            'overdue' => $overdue,
            'withoutContract' => $withoutContract,
            'overdueExpenses' => $overdueExpenses,
        ]);
    }

    public function clients(Request $request)
    {
        $query = Prospect::query()
            ->where('status', Prospect::STATUS_ACTIVE)
            ->with(['activeContract', 'artist'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('instagram', 'like', $term)
                        ->orWhere('whatsapp_e164', 'like', $term);
                });
            })
            ->when($request->filled('mode'), function ($query) use ($request): void {
                $query->whereHas('contracts', function ($inner) use ($request): void {
                    $inner->where('status', ProspectContract::STATUS_ACTIVE)
                        ->where('billing_mode', $request->input('mode'));
                });
            })
            ->when($request->boolean('without_contract'), function ($query): void {
                $query->whereDoesntHave('contracts', fn ($inner) => $inner->where('status', ProspectContract::STATUS_ACTIVE));
            })
            ->orderBy('name');

        $prospects = $query->paginate(40)->withQueryString();

        $active = ProspectContract::query()->where('status', ProspectContract::STATUS_ACTIVE);
        $mrr = (int) (clone $active)->sum('monthly_value_cents');
        $withoutContract = Prospect::query()
            ->where('status', Prospect::STATUS_ACTIVE)
            ->whereDoesntHave('contracts', fn ($q) => $q->where('status', ProspectContract::STATUS_ACTIVE))
            ->count();

        return view('finance.clients', [
            'prospects' => $prospects,
            'mrr' => $mrr,
            'withoutContract' => $withoutContract,
            'today' => Carbon::today(),
        ]);
    }
}

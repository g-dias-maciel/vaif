<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Prospect;
use App\Models\ProspectContract;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Staff-only finance cockpit. Sales users never reach these routes.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $in30 = $today->copy()->addDays(30);

        $contracts = ProspectContract::query()->where('status', ProspectContract::STATUS_ACTIVE);

        $mrr = (int) (clone $contracts)->sum('monthly_value_cents');
        $contractCount = (clone $contracts)->count();
        $cardCount = (clone $contracts)->where('billing_mode', 'card')->count();
        $pixCount = (clone $contracts)->where('billing_mode', 'pix')->count();

        $received = (int) BillingPayment::query()
            ->whereBetween('paid_at', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('amount_cents');
        $receivedCard = (int) BillingPayment::query()
            ->whereBetween('paid_at', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->where('method', BillingPayment::METHOD_CREDIT_CARD)
            ->sum('amount_cents');

        $outstanding = (int) BillingInvoice::query()
            ->open()
            ->selectRaw('coalesce(sum(amount_cents - paid_cents), 0) as total')
            ->value('total');
        $overdue = (int) BillingInvoice::query()
            ->overdue()
            ->selectRaw('coalesce(sum(amount_cents - paid_cents), 0) as total')
            ->value('total');
        $overdueCount = BillingInvoice::query()->overdue()->count();

        $invoicesNext30 = (int) BillingInvoice::query()
            ->open()
            ->whereBetween('due_date', [$today->toDateString(), $in30->toDateString()])
            ->selectRaw('coalesce(sum(amount_cents - paid_cents), 0) as total')
            ->value('total');

        $recentInvoices = BillingInvoice::query()
            ->with('prospect')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $recentPayments = BillingPayment::query()
            ->with('prospect')
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $withoutContract = Prospect::query()
            ->where('status', Prospect::STATUS_ACTIVE)
            ->whereDoesntHave('contracts', fn ($q) => $q->where('status', ProspectContract::STATUS_ACTIVE))
            ->count();

        $upcoming = ProspectContract::query()
            ->where('status', ProspectContract::STATUS_ACTIVE)
            ->with(['prospect', 'artist'])
            ->where(function ($query) use ($today, $in30): void {
                $query->whereBetween('next_invoice_at', [$today->toDateString(), $in30->toDateString()])
                    ->orWhereBetween('next_charge_at', [$today->toDateString(), $in30->toDateString()]);
            })
            ->orderBy('next_invoice_at')
            ->orderBy('next_charge_at')
            ->limit(10)
            ->get();

        return view('finance.dashboard', [
            'mrr' => $mrr,
            'contractCount' => $contractCount,
            'cardCount' => $cardCount,
            'pixCount' => $pixCount,
            'received' => $received,
            'receivedCard' => $receivedCard,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'overdueCount' => $overdueCount,
            'invoicesNext30' => $invoicesNext30,
            'recentInvoices' => $recentInvoices,
            'recentPayments' => $recentPayments,
            'withoutContract' => $withoutContract,
            'upcoming' => $upcoming,
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

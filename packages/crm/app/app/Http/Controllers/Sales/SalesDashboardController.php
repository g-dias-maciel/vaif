<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\ProspectContract;
use App\Models\ProspectStage;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SalesDashboardController extends Controller
{
    public function __invoke(): View
    {
        $active = Prospect::active()->count();
        $backlog = Prospect::backlog()->count();
        $bookedBacklog = Prospect::backlog()->whereNotNull('booked_call_at')->count();
        $mrrCents = (int) ProspectContract::active()->sum('monthly_value_cents');

        $bands = Prospect::active()
            ->select('revenue_band', DB::raw('count(*) as total'))
            ->groupBy('revenue_band')
            ->pluck('total', 'revenue_band');

        $stages = ProspectStage::withCount([
            'prospects' => fn ($query) => $query->where('status', Prospect::STATUS_ACTIVE),
        ])->orderBy('sort_order')->get();

        $recent = Prospect::active()
            ->with(['stage', 'owner'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $campaigns = Campaign::withCount('prospects')->orderByDesc('created_at')->limit(5)->get();

        return view('sales.dashboard', compact(
            'active',
            'backlog',
            'bookedBacklog',
            'mrrCents',
            'bands',
            'stages',
            'recent',
            'campaigns',
        ));
    }
}

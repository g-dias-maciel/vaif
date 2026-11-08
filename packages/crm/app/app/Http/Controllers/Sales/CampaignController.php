<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Campaign::class);

        $campaigns = Campaign::withCount('prospects')->orderByDesc('created_at')->get();

        return view('sales.campaigns.index', compact('campaigns'));
    }
}

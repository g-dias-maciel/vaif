<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BacklogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Prospect::class);

        $query = Prospect::backlog()->with('owner');

        if ($band = $request->query('band')) {
            $query->where('revenue_band', $band);
        }

        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }

        if ($request->boolean('booked')) {
            $query->whereNotNull('booked_call_at');
        }

        if ($request->boolean('with_revenue')) {
            $query->whereNotNull('revenue_monthly_cents');
        }

        if ($q = trim((string) $request->query('q'))) {
            $needle = '%'.mb_strtolower($q).'%';

            $query->where(function ($where) use ($needle) {
                $where->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(instagram) like ?', [$needle]);
            });
        }

        $prospects = $query
            ->orderByRaw("case revenue_band
                when 'full_service' then 1
                when 'retainer' then 2
                when 'entry' then 3
                when 'nurture' then 4
                else 5 end")
            ->orderByRaw('booked_call_at is null')
            ->orderByDesc('booked_call_at')
            ->orderByDesc('revenue_monthly_cents')
            ->limit(300)
            ->get();

        $sources = Prospect::backlog()
            ->select('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source');

        $bands = Prospect::bandLabels();

        return view('sales.backlog', compact('prospects', 'sources', 'bands'));
    }

    public function promote(Request $request, Prospect $prospect): RedirectResponse
    {
        Gate::authorize('update', $prospect);

        if (! $prospect->isBacklog()) {
            return back()->with('error', 'Este prospect não está no backlog.');
        }

        $prospect->update([
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'novo',
            'next_action_at' => now()->addDay(),
            'owner_id' => $prospect->owner_id ?? $request->user()->id,
        ]);

        $prospect->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'stage_change',
            'title' => 'Promovido do backlog',
            'metadata' => [
                'from' => Prospect::STATUS_BACKLOG,
                'to' => 'novo',
            ],
        ]);

        return back()->with('status', $prospect->name.' foi promovido ao pipeline.');
    }
}

<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Models\ProspectStage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProspectController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Prospect::class);

        $status = (string) $request->query('status', Prospect::STATUS_ACTIVE);

        if (! in_array($status, [Prospect::STATUS_ACTIVE, Prospect::STATUS_BACKLOG, Prospect::STATUS_ARCHIVED], true)) {
            $status = Prospect::STATUS_ACTIVE;
        }

        $query = Prospect::query()
            ->with(['stage', 'owner', 'campaign'])
            ->where('status', $status);

        if ($stage = $request->query('stage')) {
            $query->where('stage_key', $stage);
        }

        if ($band = $request->query('band')) {
            $query->where('revenue_band', $band);
        }

        if ($owner = $request->query('owner')) {
            $query->where('owner_id', $owner);
        }

        if ($q = trim((string) $request->query('q'))) {
            $needle = '%'.mb_strtolower($q).'%';
            $digits = (string) preg_replace('/\D+/', '', $q);

            $query->where(function ($where) use ($needle, $digits) {
                $where->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(instagram) like ?', [$needle]);

                if ($digits !== '') {
                    $where->orWhere('whatsapp_e164', 'like', '%'.$digits.'%');
                }
            });
        }

        $prospects = $query->orderByDesc('updated_at')->limit(200)->get();
        $stages = ProspectStage::orderBy('sort_order')->get();
        $owners = User::whereIn('role', [User::ROLE_SALES, User::ROLE_STAFF])->orderBy('name')->get();
        $bands = Prospect::bandLabels();

        return view('sales.pipeline', compact('prospects', 'stages', 'owners', 'bands', 'status'));
    }
}

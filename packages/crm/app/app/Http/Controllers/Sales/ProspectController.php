<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\HandoffRequest;
use App\Http\Requests\Sales\StoreActivityRequest;
use App\Http\Requests\Sales\StoreProspectRequest;
use App\Http\Requests\Sales\UpdateProspectRequest;
use App\Http\Requests\Sales\UpdateStageRequest;
use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectStage;
use App\Models\User;
use App\Services\Sales\ProspectHandoffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    public function create(Request $request): View
    {
        Gate::authorize('create', Prospect::class);

        return view('sales.prospects.form', [
            'prospect' => new Prospect,
            'owners' => $this->ownersFor($request),
            'campaigns' => Campaign::orderBy('name')->get(),
            'stages' => ProspectStage::orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreProspectRequest $request): RedirectResponse
    {
        Gate::authorize('create', Prospect::class);

        $data = $request->validated();
        $requestedOwner = $data['owner_id'] ?? null;

        if ($request->user()->isSales()
            && $requestedOwner !== null
            && (int) $requestedOwner !== (int) $request->user()->id) {
            return back()
                ->withErrors(['owner_id' => 'Você só pode atribuir prospects a si mesmo.'])
                ->withInput();
        }

        $prospect = new Prospect;
        $this->fillFromData($prospect, $data, $requestedOwner ?? $request->user()->id);
        $prospect->status = Prospect::STATUS_ACTIVE;
        $prospect->stage_key = 'novo';
        $prospect->source = $data['source'] ?? 'manual';

        if ($prospect->next_action_at === null) {
            $prospect->next_action_at = now()->addDay();
        }

        $prospect->save();

        $prospect->activities()->create([
            'user_id' => $request->user()->id,
            'type' => ProspectActivity::TYPE_NOTE,
            'title' => 'Prospect criado manualmente',
            'completed_at' => now(),
        ]);

        return redirect()
            ->route('sales.prospects.show', $prospect)
            ->with('status', 'Prospect criado.');
    }

    public function show(Prospect $prospect): View
    {
        Gate::authorize('view', $prospect);

        $prospect->load([
            'stage',
            'owner',
            'campaign',
            'contracts',
            'activities' => fn ($query) => $query->with('user')->orderByDesc('created_at'),
        ]);

        $stages = ProspectStage::where('key', '!=', 'ganho')
            ->orderBy('sort_order')
            ->get();

        return view('sales.prospects.show', compact('prospect', 'stages'));
    }

    public function edit(Request $request, Prospect $prospect): View
    {
        Gate::authorize('update', $prospect);
        $this->ensureCanWork($request, $prospect);

        return view('sales.prospects.form', [
            'prospect' => $prospect,
            'owners' => $this->ownersFor($request),
            'campaigns' => Campaign::orderBy('name')->get(),
            'stages' => ProspectStage::orderBy('sort_order')->get(),
        ]);
    }

    public function update(UpdateProspectRequest $request, Prospect $prospect): RedirectResponse
    {
        Gate::authorize('update', $prospect);
        $this->ensureCanWork($request, $prospect);

        $data = $request->validated();

        if ($request->user()->isSales()) {
            $requestedOwner = $data['owner_id'] ?? null;

            if ($requestedOwner !== null && (int) $requestedOwner !== (int) $request->user()->id) {
                return back()
                    ->withErrors(['owner_id' => 'Você só pode atribuir prospects a si mesmo.'])
                    ->withInput();
            }
        }

        $ownerId = array_key_exists('owner_id', $data) && $data['owner_id'] !== null
            ? (int) $data['owner_id']
            : ($request->user()->isStaff() ? null : ($prospect->owner_id ?? $request->user()->id));

        $this->fillFromData($prospect, $data, $ownerId);

        if (! empty($data['source'])) {
            $prospect->source = $data['source'];
        }

        $prospect->save();

        return redirect()
            ->route('sales.prospects.show', $prospect)
            ->with('status', 'Prospect atualizado.');
    }

    public function updateStage(UpdateStageRequest $request, Prospect $prospect): RedirectResponse
    {
        Gate::authorize('update', $prospect);
        $this->ensureCanWork($request, $prospect);

        $data = $request->validated();
        $from = $prospect->stage_key;
        $to = $data['stage_key'];

        if ($to === 'ganho') {
            return back()->with('error', 'O handoff ainda não está disponível. Mantenha o prospect em Proposta.');
        }

        if ($from === $to) {
            return back()->with('status', 'O prospect já está neste estágio.');
        }

        $attributes = ['stage_key' => $to];

        if ($to === 'perdido') {
            $attributes['lost_reason'] = $data['lost_reason'];
            $attributes['next_action_at'] = null;
        } else {
            $attributes['lost_reason'] = null;
        }

        if ($to === 'nutricao') {
            $attributes['next_action_at'] = now()->addDays(30);
        } elseif (in_array($to, ['novo', 'qualificado'], true)) {
            $attributes['next_action_at'] = now()->addDay();
        } elseif ($to === 'call_agendada') {
            $attributes['next_action_at'] = now()->addDays(2);

            if ($prospect->booked_call_at === null) {
                $attributes['booked_call_at'] = now();
            }
        } elseif ($to === 'call_realizada') {
            $attributes['next_action_at'] = now()->addDay();
            $attributes['last_contact_at'] = now();
        } elseif ($to === 'proposta') {
            $attributes['next_action_at'] = now()->addDays(2);
        }

        $prospect->update($attributes);

        $fromLabel = ProspectStage::whereKey($from)->value('label_pt') ?? ($from ?? '—');
        $toLabel = ProspectStage::whereKey($to)->value('label_pt') ?? $to;

        $prospect->activities()->create([
            'user_id' => $request->user()->id,
            'type' => ProspectActivity::TYPE_STAGE_CHANGE,
            'title' => 'Estágio: '.$fromLabel.' → '.$toLabel,
            'body' => $data['note'] ?? null,
            'metadata' => [
                'from' => $from,
                'to' => $to,
                'lost_reason' => $data['lost_reason'] ?? null,
            ],
            'completed_at' => now(),
        ]);

        return back()->with('status', 'Estágio atualizado para '.$toLabel.'.');
    }

    public function updateNextAction(Request $request, Prospect $prospect): RedirectResponse
    {
        Gate::authorize('update', $prospect);
        $this->ensureCanWork($request, $prospect);

        $data = $request->validate([
            'next_action_at' => ['nullable', 'date'],
            'next_action_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $prospect->update($data);

        $prospect->activities()->create([
            'user_id' => $request->user()->id,
            'type' => ProspectActivity::TYPE_NOTE,
            'title' => 'Próxima ação atualizada',
            'body' => $data['next_action_note'] ?? null,
            'completed_at' => now(),
        ]);

        return back()->with('status', 'Próxima ação atualizada.');
    }

    public function storeActivity(StoreActivityRequest $request, Prospect $prospect): RedirectResponse
    {
        Gate::authorize('update', $prospect);
        $this->ensureCanWork($request, $prospect);

        $data = $request->validated();
        $isTask = $data['type'] === ProspectActivity::TYPE_TASK;

        $prospect->activities()->create([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'completed_at' => $isTask ? null : now(),
        ]);

        if (in_array($data['type'], [ProspectActivity::TYPE_CALL, ProspectActivity::TYPE_WHATSAPP], true)) {
            $prospect->update(['last_contact_at' => now()]);
        }

        return back()->with('status', 'Atividade registrada.');
    }

    public function handoff(
        HandoffRequest $request,
        Prospect $prospect,
        ProspectHandoffService $service,
    ): RedirectResponse {
        Gate::authorize('handoff', $prospect);
        $this->ensureCanWork($request, $prospect);

        $result = $service->handoff($prospect, $request->validated(), $request->user());

        $message = 'Handoff concluído.';

        if (! $result['invite_sent']) {
            $message .= ' O convite do artista não pôde ser enviado; reenvie em Artistas.';
        }

        return redirect()
            ->route('sales.prospects.show', $prospect)
            ->with('status', $message);
    }

    public function completeActivity(Request $request, Prospect $prospect, ProspectActivity $activity): RedirectResponse
    {
        Gate::authorize('update', $prospect);
        $this->ensureCanWork($request, $prospect);

        abort_unless($activity->prospect_id === $prospect->id, 404);

        $activity->update(['completed_at' => now()]);

        return back()->with('status', 'Tarefa concluída.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillFromData(Prospect $prospect, array $data, ?int $ownerId): void
    {
        $prospect->fill([
            'name' => $data['name'],
            'whatsapp_e164' => $data['whatsapp_e164'],
            'whatsapp_raw' => $data['whatsapp'],
            'instagram' => $data['instagram'] ?? null,
            'email' => $data['email'] ?? null,
            'studio' => $data['studio'] ?? null,
            'style' => $data['style'] ?? null,
            'city' => $data['city'] ?? null,
            'revenue_monthly_cents' => $this->toCents($data['revenue_monthly'] ?? null),
            'ticket_cents' => $this->toCents($data['ticket'] ?? null),
            'sessions_monthly' => $data['sessions_monthly'] ?? null,
            'admin_hours_day' => $data['admin_hours_day'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'owner_id' => $ownerId,
            'next_action_at' => $data['next_action_at'] ?? null,
            'next_action_note' => $data['next_action_note'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function ensureCanWork(Request $request, Prospect $prospect): void
    {
        if ($request->user()->isSales()
            && $prospect->owner_id !== null
            && (int) $prospect->owner_id !== (int) $request->user()->id) {
            abort(403, 'Este prospect pertence a outro vendedor.');
        }
    }

    private function toCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * @return Collection<int, User>
     */
    private function ownersFor(Request $request): Collection
    {
        if ($request->user()->isStaff()) {
            return User::whereIn('role', [User::ROLE_SALES, User::ROLE_STAFF])
                ->orderBy('name')
                ->get();
        }

        return User::whereKey($request->user()->id)->get();
    }
}

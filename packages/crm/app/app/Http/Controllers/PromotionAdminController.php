<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\PriceBook;
use App\Models\Promotion;
use App\Models\PromotionType;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * VAIF staff: create, assign (to an Artist) and edit promotions.
 *
 * Promotions are Artist-specific; the mechanic comes from promotion_types.
 * Activation is delegated to the Postgres RPC so the price snapshot and the
 * one-active-promotion rule stay authoritative.
 */
class PromotionAdminController extends Controller
{
    public function index(Request $request)
    {
        $promotions = Promotion::with(['artist.settings', 'type'])
            ->when($request->filled('artist_id'), fn ($q) => $q->where('artist_id', $request->input('artist_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('promotions.index', [
            'promotions' => $promotions,
            'artists' => Artist::with('settings')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $source = null;
        if ($request->filled('from')) {
            $source = Promotion::find($request->query('from'));
        }

        return view('promotions.form', [
            'promotion' => null,
            'source' => $source,
            'artists' => Artist::with('settings')->orderBy('name')->get(),
            'types' => $this->types(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        $source = $request->filled('source_id')
            ? Promotion::find($request->input('source_id'))
            : null;

        $promotion = Promotion::create([
            'artist_id' => $data['artist_id'],
            'type_key' => $data['type_key'],
            'name' => $data['name'],
            'multiplier' => $data['multiplier'],
            'audience' => $data['audience'],
            'quote_valid_days' => $data['quote_valid_days'],
            'conditions' => $source?->conditions ?? (object) [],
            'status' => 'draft',
        ]);

        return redirect()
            ->route('promotions.edit', $promotion)
            ->with('status', 'Promoção criada como rascunho. Revise e ative quando estiver pronta.');
    }

    public function edit(Promotion $promotion)
    {
        $promotion->load(['artist.settings', 'type']);

        return view('promotions.form', [
            'promotion' => $promotion,
            'source' => null,
            'artists' => Artist::with('settings')->orderBy('name')->get(),
            'types' => $this->types(),
        ]);
    }

    public function update(Request $request, Promotion $promotion)
    {
        if ($promotion->status === 'ended') {
            return back()->with('error', 'Uma promoção encerrada não pode ser editada.');
        }

        $data = $this->validated($request, false);

        $payload = [
            'name' => $data['name'],
            'audience' => $data['audience'],
            'quote_valid_days' => $data['quote_valid_days'],
        ];

        // The multiplier is snapshotted into promotion_prices on activation;
        // changing it while active would desync the prices the agent quotes.
        if ($promotion->status !== 'active') {
            $payload['type_key'] = $data['type_key'];
            $payload['multiplier'] = $data['multiplier'];
        }

        $promotion->update($payload);

        return redirect()
            ->route('promotions.index')
            ->with('status', 'Promoção atualizada.');
    }

    public function activate(Promotion $promotion)
    {
        if ($promotion->status === 'active') {
            return back()->with('error', 'Esta promoção já está ativa.');
        }
        if ($promotion->status === 'ended') {
            return back()->with('error', 'Uma promoção encerrada não pode ser reativada. Crie uma nova.');
        }

        if (! PriceBook::where('artist_id', $promotion->artist_id)->whereNull('effective_to')->exists()) {
            return back()->with('error', 'O artista não tem uma tabela de preços vigente para gerar a promoção.');
        }

        try {
            DB::select('select * from activate_promotion(?, ?)', [$promotion->artist_id, $promotion->id]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23505') {
                return back()->with('error', 'Já existe outra promoção ativa para este artista. Pause ou encerre a atual primeiro.');
            }

            throw $e;
        }

        $promotion->refresh();

        return back()->with('status', $promotion->isActive()
            ? 'Promoção ativada. Os preços foram capturados da tabela atual.'
            : 'Não foi possível ativar esta promoção.');
    }

    public function pause(Promotion $promotion)
    {
        if ($promotion->status !== 'active') {
            return back()->with('error', 'Só uma promoção ativa pode ser pausada.');
        }

        DB::select('select * from pause_promotion(?, ?)', [$promotion->artist_id, $promotion->id]);

        return back()->with('status', 'Promoção pausada.');
    }

    public function end(Promotion $promotion)
    {
        if (! in_array($promotion->status, ['active', 'paused'], true)) {
            return back()->with('error', 'Só uma promoção ativa ou pausada pode ser encerrada.');
        }

        DB::select('select * from end_promotion(?, ?)', [$promotion->artist_id, $promotion->id]);

        return back()->with('status', 'Promoção encerrada. Cotações abertas dessa promoção foram anuladas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type_key' => ['required', Rule::exists('promotion_types', 'key')->where('active', true)],
            'multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'audience' => ['required', Rule::in(['all', 'new', 'returning'])],
            'quote_valid_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];

        if ($creating) {
            $rules['artist_id'] = ['required', 'uuid', Rule::exists('artists', 'id')];
            $rules['source_id'] = ['nullable', 'uuid', Rule::exists('promotions', 'id')];
        }

        return $request->validate($rules);
    }

    private function types()
    {
        return PromotionType::where('active', true)->orderBy('name')->get();
    }
}

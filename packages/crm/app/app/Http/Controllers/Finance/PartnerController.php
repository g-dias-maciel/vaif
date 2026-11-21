<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancePartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        FinancePartner::create($data);

        return redirect()
            ->route('finance.settings')
            ->with('status', 'Sócio adicionado.');
    }

    public function update(Request $request, FinancePartner $partner): RedirectResponse
    {
        $partner->update($this->validated($request, $partner));

        return redirect()
            ->route('finance.settings')
            ->with('status', 'Sócio atualizado.');
    }

    public function destroy(FinancePartner $partner): RedirectResponse
    {
        if ($partner->distributions()->exists()) {
            $partner->update(['active' => false]);

            return redirect()
                ->route('finance.settings')
                ->with('status', 'Sócio desativado (já tem distribuições registradas).');
        }

        $partner->delete();

        return redirect()
            ->route('finance.settings')
            ->with('status', 'Sócio removido.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?FinancePartner $partner = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('finance_partners', 'name')->ignore($partner?->id),
            ],
            'share_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $isActive = $request->boolean('active');
        $newShares = (int) round(((float) $data['share_percent']) * 100);

        if ($isActive) {
            $totalShares = (int) FinancePartner::query()
                ->where('active', true)
                ->when($partner !== null, fn ($query) => $query->where('id', '!=', $partner->id))
                ->sum('share_bps');

            if ($totalShares + $newShares > 10_000) {
                throw ValidationException::withMessages([
                    'share_percent' => 'A soma das participações dos sócios ativos não pode passar de 100%.',
                ]);
            }
        }

        return [
            'name' => $data['name'],
            'share_bps' => $newShares,
            'active' => $isActive,
        ];
    }
}

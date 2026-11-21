<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\DistributionRecorder;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class DistributionController extends Controller
{
    public function store(Request $request, DistributionRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'distributions' => ['nullable', 'array'],
            'distributions.*.profit' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'distributions.*.expense_ids' => ['nullable', 'array'],
            'distributions.*.expense_ids.*' => ['uuid', 'exists:billing_expenses,id'],
            'distributions.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $month = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        } catch (Throwable) {
            return back()->withErrors(['month' => 'Mês inválido.']);
        }

        $rows = [];

        foreach ((array) ($data['distributions'] ?? []) as $partnerId => $row) {
            $rows[$partnerId] = [
                'profit_cents' => (int) round(((float) ($row['profit'] ?? 0)) * 100),
                'expense_ids' => array_values((array) ($row['expense_ids'] ?? [])),
                'notes' => $row['notes'] ?? null,
            ];
        }

        $recorder->saveMonth($month, $rows, $request->user());

        return redirect()
            ->route('finance.months', ['month' => $month->format('Y-m')])
            ->with('status', 'Distribuição de '.$month->format('m/Y').' salva.');
    }
}

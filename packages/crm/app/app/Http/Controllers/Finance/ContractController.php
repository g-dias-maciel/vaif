<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff links an active client to a commercial contract. Imported Notion
 * clients arrive without contracts on purpose; this is the manual step.
 */
class ContractController extends Controller
{
    private const PLANS = [
        ProspectContract::PLAN_MENSAL,
        ProspectContract::PLAN_SEMESTRAL,
        ProspectContract::PLAN_TWELVE_MONTHS,
        ProspectContract::PLAN_FULL_SERVICE,
        ProspectContract::PLAN_MEMBERS_AREA,
    ];

    private const METHODS = ['pix', 'credit_card', 'boleto', 'transfer', 'cash', 'other'];

    public function create(Prospect $prospect)
    {
        $prospect->load(['artist', 'contracts']);

        return view('finance.contracts.form', [
            'prospect' => $prospect,
            'contract' => new ProspectContract([
                'prospect_id' => $prospect->id,
                'plan' => ProspectContract::PLAN_SEMESTRAL,
                'billing_mode' => 'pix',
                'payment_method' => 'pix',
                'status' => ProspectContract::STATUS_ACTIVE,
                'start_date' => now()->toDateString(),
                'billing_day' => 10,
            ]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, Prospect $prospect): RedirectResponse
    {
        $data = $this->validated($request, $prospect);

        $this->guardAgainstDuplicateActive($prospect, null, $data['status']);

        $contract = $prospect->contracts()->create($this->attributes($data, $prospect));

        $this->log($request, $prospect, $contract, true);

        return redirect()
            ->route('finance.clients')
            ->with('status', 'Contrato criado para '.$prospect->name.'.');
    }

    public function edit(ProspectContract $contract)
    {
        $contract->load(['prospect.artist', 'invoices', 'payments']);

        return view('finance.contracts.form', [
            'prospect' => $contract->prospect,
            'contract' => $contract,
            'today' => now()->toDateString(),
        ]);
    }

    public function update(Request $request, ProspectContract $contract): RedirectResponse
    {
        $prospect = $contract->prospect;
        $data = $this->validated($request, $prospect);

        $this->guardAgainstDuplicateActive($prospect, $contract->id, $data['status']);

        $oldPlan = $contract->plan;
        $contract->update($this->attributes($data, $prospect, $contract));

        $this->log($request, $prospect, $contract, false, $oldPlan);

        return redirect()
            ->route('finance.clients')
            ->with('status', 'Contrato atualizado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Prospect $prospect): array
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(self::PLANS)],
            'monthly_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'total_value' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'billing_day' => ['nullable', 'integer', 'between:1,31'],
            'billing_mode' => ['required', Rule::in(['pix', 'card'])],
            'payment_method' => ['nullable', Rule::in(self::METHODS)],
            'status' => ['required', Rule::in([
                ProspectContract::STATUS_DRAFT,
                ProspectContract::STATUS_ACTIVE,
                ProspectContract::STATUS_ENDED,
                ProspectContract::STATUS_CHURNED,
            ])],
            'signed_at' => ['nullable', 'date'],
            'next_invoice_at' => ['nullable', 'date'],
            'next_charge_at' => ['nullable', 'date'],
            'mercado_pago_preapproval_id' => ['nullable', 'string', 'max:255'],
            'mercado_pago_customer_id' => ['nullable', 'string', 'max:255'],
            'notion_page_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! in_array($data['plan'], [ProspectContract::PLAN_MEMBERS_AREA, ProspectContract::PLAN_FULL_SERVICE], true)
            && (float) ($data['monthly_value'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'monthly_value' => 'Informe o valor mensal do contrato.',
            ]);
        }

        if ($data['plan'] === ProspectContract::PLAN_FULL_SERVICE
            && (float) ($data['total_value'] ?? 0) <= 0
            && (float) ($data['monthly_value'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'total_value' => 'Informe o valor do full service.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Prospect $prospect, ?ProspectContract $current = null): array
    {
        $plan = (string) $data['plan'];
        $months = match ($plan) {
            ProspectContract::PLAN_SEMESTRAL, ProspectContract::PLAN_FULL_SERVICE => 6,
            ProspectContract::PLAN_TWELVE_MONTHS => 12,
            default => 1,
        };

        $monthly = (int) round(((float) ($data['monthly_value'] ?? 0)) * 100);
        $total = filled($data['total_value'] ?? null)
            ? (int) round(((float) $data['total_value']) * 100)
            : $monthly * $months;

        if ($plan === ProspectContract::PLAN_MEMBERS_AREA) {
            $monthly = 0;
            $total = $total > 0 ? $total : 69_700;
        } elseif ($plan === ProspectContract::PLAN_FULL_SERVICE && $total <= 0) {
            $total = 1_500_000;
        }

        $billingMode = (string) $data['billing_mode'];
        $status = (string) $data['status'];
        $start = \Carbon\Carbon::parse($data['start_date'])->startOfDay();

        $nextInvoice = $data['next_invoice_at'] ?? null;
        $nextCharge = $data['next_charge_at'] ?? null;

        if ($status !== ProspectContract::STATUS_ACTIVE) {
            $nextInvoice = null;
            $nextCharge = null;
        } elseif ($billingMode === 'card') {
            $nextInvoice = null;
            $nextCharge = $nextCharge ?: $start->copy()->addMonthNoOverflow()->toDateString();
        } elseif ($plan === ProspectContract::PLAN_MEMBERS_AREA) {
            // One-off 697: no recurring invoice unless staff sets a date.
            $nextInvoice = $nextInvoice ?: null;
            $nextCharge = null;
        } else {
            $nextInvoice = $nextInvoice ?: $start->toDateString();
            $nextCharge = null;
        }

        return [
            'artist_id' => $plan === ProspectContract::PLAN_MEMBERS_AREA ? null : $prospect->artist_id,
            'plan' => $plan,
            'monthly_value_cents' => $monthly,
            'total_value_cents' => $total,
            'start_date' => $start->toDateString(),
            'end_date' => $data['end_date'] ?? null,
            'status' => $status,
            'signed_at' => $data['signed_at'] ?? ($status === ProspectContract::STATUS_ACTIVE ? now() : null),
            'billing_day' => $data['billing_day'] ?? null,
            'next_invoice_at' => $nextInvoice,
            'next_charge_at' => $nextCharge,
            'billing_mode' => $billingMode,
            'payment_method' => $data['payment_method'] ?? ($billingMode === 'card' ? 'credit_card' : 'pix'),
            'mercado_pago_preapproval_id' => $data['mercado_pago_preapproval_id'] ?? null,
            'mercado_pago_customer_id' => $data['mercado_pago_customer_id'] ?? null,
            'notion_page_id' => $data['notion_page_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function guardAgainstDuplicateActive(Prospect $prospect, ?string $ignoreId, string $status): void
    {
        if ($status !== ProspectContract::STATUS_ACTIVE) {
            return;
        }

        $exists = $prospect->contracts()
            ->where('status', ProspectContract::STATUS_ACTIVE)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'status' => 'Este cliente já tem um contrato ativo. Encerre o anterior antes de criar outro.',
            ]);
        }
    }

    private function log(
        Request $request,
        Prospect $prospect,
        ProspectContract $contract,
        bool $created,
        ?string $oldPlan = null,
    ): void {
        $prospect->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ProspectActivity::TYPE_CONTRACT,
            'title' => $created ? 'Contrato criado' : 'Contrato atualizado',
            'body' => sprintf(
                '%s — R$ %s/mês · %s',
                $contract->planLabel(),
                number_format($contract->monthly_value_cents / 100, 2, ',', '.'),
                $contract->billingModeLabel(),
            ),
            'metadata' => array_filter([
                'contract_id' => $contract->id,
                'plan' => $contract->plan,
                'old_plan' => $oldPlan,
                'billing_mode' => $contract->billing_mode,
                'status' => $contract->status,
            ]),
            'completed_at' => now(),
        ]);
    }
}

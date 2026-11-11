<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\ProspectActivity;
use App\Models\ProspectContract;
use App\Services\Finance\PaymentRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = BillingPayment::query()
            ->with(['prospect', 'contract', 'invoice', 'recorder'])
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->input('method')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->input('to')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('reference', 'like', $term)
                        ->orWhere('external_ref', 'like', $term)
                        ->orWhereHas('prospect', fn ($p) => $p->where('name', 'like', $term));
                });
            })
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at');

        $payments = $query->paginate(40)->withQueryString();

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $received = (int) BillingPayment::whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount_cents');
        $card = (int) BillingPayment::whereBetween('paid_at', [$monthStart, $monthEnd])
            ->where('method', BillingPayment::METHOD_CREDIT_CARD)
            ->sum('amount_cents');

        $contracts = ProspectContract::query()
            ->with('prospect')
            ->where('status', ProspectContract::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        return view('finance.payments.index', [
            'payments' => $payments,
            'contracts' => $contracts,
            'received' => $received,
            'card' => $card,
            'selectedContract' => $request->filled('contract')
                ? $contracts->firstWhere('id', $request->input('contract'))
                : null,
        ]);
    }

    public function store(Request $request, PaymentRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'uuid', 'exists:billing_invoices,id'],
            'contract_id' => ['nullable', 'uuid', 'exists:prospect_contracts,id'],
            'prospect_id' => ['nullable', 'uuid', 'exists:prospects,id'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', Rule::in([
                BillingPayment::METHOD_PIX,
                BillingPayment::METHOD_CREDIT_CARD,
                BillingPayment::METHOD_BOLETO,
                BillingPayment::METHOD_TRANSFER,
                BillingPayment::METHOD_CASH,
                BillingPayment::METHOD_OTHER,
            ])],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in([
                BillingPayment::SOURCE_MANUAL,
                BillingPayment::SOURCE_MERCADO_PAGO,
                BillingPayment::SOURCE_N8N,
                BillingPayment::SOURCE_NOTION,
            ])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (empty($data['invoice_id']) && empty($data['contract_id']) && empty($data['prospect_id'])) {
            return back()->withErrors(['contract_id' => 'Escolha uma cobrança ou um contrato.'])->withInput();
        }

        if (! empty($data['invoice_id']) && empty($data['amount'])) {
            $data['amount_cents'] = BillingInvoice::find($data['invoice_id'])?->balanceCents() ?? 0;
        } elseif (! empty($data['amount'])) {
            $data['amount_cents'] = (int) round(((float) $data['amount']) * 100);
        }

        $payment = $recorder->record($data, $request->user());

        $payment->prospect?->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ProspectActivity::TYPE_CONTRACT,
            'title' => 'Pagamento recebido',
            'body' => 'R$ '.number_format($payment->amount_cents / 100, 2, ',', '.').' · '.$payment->methodLabel(),
            'metadata' => array_filter([
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'contract_id' => $payment->contract_id,
                'method' => $payment->method,
                'source' => $payment->source,
            ]),
            'completed_at' => now(),
        ]);

        if ($payment->invoice_id !== null) {
            return redirect()
                ->route('finance.invoices.show', $payment->invoice_id)
                ->with('status', 'Pagamento registrado.');
        }

        return redirect()
            ->route('finance.payments.index')
            ->with('status', 'Pagamento registrado.');
    }
}

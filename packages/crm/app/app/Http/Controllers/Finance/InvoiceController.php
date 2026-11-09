<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingSetting;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectContract;
use App\Services\Finance\InvoiceNumberGenerator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = BillingInvoice::query()
            ->with(['prospect', 'contract'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('number', 'like', $term)
                        ->orWhereHas('prospect', fn ($p) => $p->where('name', 'like', $term));
                });
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('due_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('due_date', '<=', $request->input('to')))
            ->orderByDesc('due_date')
            ->orderByDesc('created_at');

        $invoices = $query->paginate(40)->withQueryString();

        $open = BillingInvoice::query()->open();
        $outstanding = (int) (clone $open)->selectRaw('coalesce(sum(amount_cents - paid_cents), 0) as total')->value('total');
        $overdue = (int) BillingInvoice::query()->overdue()->selectRaw('coalesce(sum(amount_cents - paid_cents), 0) as total')->value('total');

        return view('finance.invoices.index', [
            'invoices' => $invoices,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
        ]);
    }

    public function create(Request $request)
    {
        $contracts = ProspectContract::query()
            ->with('prospect')
            ->whereIn('status', [ProspectContract::STATUS_ACTIVE, ProspectContract::STATUS_DRAFT])
            ->orderBy('id')
            ->get();

        $selected = $request->filled('contract')
            ? $contracts->firstWhere('id', $request->input('contract'))
            : null;

        $prospects = Prospect::query()
            ->where('status', Prospect::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name']);

        $start = now()->startOfDay();

        return view('finance.invoices.form', [
            'contracts' => $contracts,
            'prospects' => $prospects,
            'selected' => $selected,
            'invoice' => new BillingInvoice([
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
                'issue_date' => $start->toDateString(),
                'due_date' => $start->toDateString(),
                'status' => BillingInvoice::STATUS_SENT,
                'payment_method' => BillingInvoice::METHOD_PIX,
            ]),
        ]);
    }

    public function store(Request $request, InvoiceNumberGenerator $numbers): RedirectResponse
    {
        $data = $request->validate([
            'prospect_id' => ['required', 'uuid', 'exists:prospects,id'],
            'contract_id' => ['nullable', 'uuid', 'exists:prospect_contracts,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'status' => ['required', Rule::in([BillingInvoice::STATUS_DRAFT, BillingInvoice::STATUS_SENT])],
            'payment_method' => ['required', Rule::in([
                BillingInvoice::METHOD_PIX,
                BillingInvoice::METHOD_BOLETO,
                BillingInvoice::METHOD_TRANSFER,
                BillingInvoice::METHOD_CASH,
                BillingInvoice::METHOD_OTHER,
            ])],
            'description' => ['required', 'string', 'max:255'],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (($data['contract_id'] ?? null) !== null) {
            $contract = ProspectContract::find($data['contract_id']);

            if ($contract === null || $contract->prospect_id !== $data['prospect_id']) {
                return back()->withErrors(['contract_id' => 'O contrato não pertence a este cliente.'])->withInput();
            }
        }

        $amount = (int) round(((float) $data['amount']) * 100);

        $invoice = BillingInvoice::create([
            'number' => $numbers->next(Carbon::parse($data['issue_date'])),
            'contract_id' => $data['contract_id'] ?? null,
            'prospect_id' => $data['prospect_id'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'amount_cents' => $amount,
            'paid_cents' => 0,
            'status' => $data['status'],
            'payment_method' => $data['payment_method'],
            'external_ref' => $data['external_ref'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->items()->create([
            'description' => $data['description'],
            'quantity' => 1,
            'unit_amount_cents' => $amount,
            'total_cents' => $amount,
            'sort_order' => 0,
        ]);

        $invoice->prospect?->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ProspectActivity::TYPE_CONTRACT,
            'title' => 'Cobrança criada',
            'body' => $invoice->number.' — R$ '.number_format($amount / 100, 2, ',', '.'),
            'metadata' => ['invoice_id' => $invoice->id, 'status' => $invoice->status],
            'completed_at' => now(),
        ]);

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', 'Cobrança '.$invoice->number.' criada.');
    }

    public function show(BillingInvoice $invoice)
    {
        $invoice->load(['prospect', 'contract', 'items', 'payments.recorder']);

        return view('finance.invoices.show', [
            'invoice' => $invoice,
            'setting' => BillingSetting::current(),
        ]);
    }

    public function cancel(Request $request, BillingInvoice $invoice): RedirectResponse
    {
        if ($invoice->isPaid()) {
            return back()->withErrors(['invoice' => 'Cobrança paga não pode ser cancelada.']);
        }

        $invoice->update(['status' => BillingInvoice::STATUS_CANCELLED]);

        $invoice->prospect?->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ProspectActivity::TYPE_CONTRACT,
            'title' => 'Cobrança cancelada',
            'body' => $invoice->number,
            'metadata' => ['invoice_id' => $invoice->id],
            'completed_at' => now(),
        ]);

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('status', 'Cobrança cancelada.');
    }

    public function receipt(BillingInvoice $invoice)
    {
        $invoice->load(['prospect', 'contract', 'items', 'payments']);

        return view('finance.invoices.receipt', [
            'invoice' => $invoice,
            'setting' => BillingSetting::current(),
        ]);
    }
}

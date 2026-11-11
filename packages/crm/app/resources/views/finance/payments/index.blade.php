@extends('layouts.app')

@section('title', 'Pagamentos')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h1 style="margin:0;">Pagamentos</h1>
            <p class="meta" style="margin-top:6px;">Receita recebida. Cartão do Mercado Pago entra aqui sem cobrança vinculada.</p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.dashboard') }}" class="btn btn--ghost btn--small">Financeiro</a>
            <a href="{{ route('finance.invoices.index') }}" class="btn btn--ghost btn--small">Cobranças</a>
        </div>
    </div>

    <div class="fin-grid" style="margin-bottom:18px;">
        <div class="fin-card">
            <div class="label">Recebido no mês</div>
            <div class="value fin-tone-ok"><x-money :cents="$received" /></div>
        </div>
        <div class="fin-card">
            <div class="label">Cartão no mês</div>
            <div class="value"><x-money :cents="$card" /></div>
            <div class="hint">Mercado Pago recorrente</div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1.4fr 1fr; gap:22px; margin-bottom:24px;">
        <div>
            <h2 style="font-size:1rem; margin:0 0 10px;">Registrar pagamento</h2>
            <form method="post" action="{{ route('finance.payments.store') }}" class="sales-form" id="paymentForm">
                @csrf

                <label class="full">
                    Contrato
                    <select name="contract_id" id="paymentContract" required>
                        <option value="">Selecione</option>
                        @foreach ($contracts as $contract)
                            <option value="{{ $contract->id }}"
                                data-prospect="{{ $contract->prospect_id }}"
                                data-amount="{{ number_format($contract->monthly_value_cents / 100, 2, '.', '') }}"
                                data-method="{{ $contract->payment_method ?: ($contract->isCard() ? 'credit_card' : 'pix') }}"
                                @selected(old('contract_id', $selectedContract?->id) === $contract->id)>
                                {{ $contract->prospect?->name }} — {{ $contract->planLabel() }} — {{ $contract->billingModeLabel() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Valor (R$)
                    <input type="number" step="0.01" min="0.01" name="amount" id="paymentAmount" value="{{ old('amount', $selectedContract ? number_format($selectedContract->monthly_value_cents / 100, 2, '.', '') : '') }}" required>
                </label>

                <label>
                    Data
                    <input type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" required>
                </label>

                <label>
                    Método
                    <select name="method" id="paymentMethod">
                        @foreach (['credit_card' => 'Cartão de crédito', 'pix' => 'PIX', 'boleto' => 'Boleto', 'transfer' => 'Transferência', 'cash' => 'Dinheiro', 'other' => 'Outro'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('method', $selectedContract && $selectedContract->isCard() ? 'credit_card' : 'pix') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Origem
                    <select name="source">
                        <option value="manual">Manual</option>
                        <option value="mercado_pago">Mercado Pago</option>
                        <option value="n8n">n8n</option>
                        <option value="notion">Notion</option>
                    </select>
                </label>

                <label>
                    Referência
                    <input type="text" name="reference" value="{{ old('reference') }}" placeholder="Comprovante">
                </label>

                <label>
                    ID externo
                    <input type="text" name="external_ref" value="{{ old('external_ref') }}" placeholder="ID Mercado Pago">
                </label>

                <label class="full">
                    Observações
                    <textarea name="notes">{{ old('notes') }}</textarea>
                </label>

                <div class="full">
                    <input type="hidden" name="prospect_id" id="paymentProspect" value="{{ $selectedContract?->prospect_id }}">
                    <button type="submit" class="btn">Registrar pagamento</button>
                </div>
            </form>
        </div>

        <div>
            <h2 style="font-size:1rem; margin:0 0 10px;">Filtros</h2>
            <form method="get" data-auto-filter action="{{ route('finance.payments.index') }}" class="sales-toolbar" style="flex-direction:column; align-items:stretch;">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Cliente, referência ou ID externo">
                <select name="method">
                    <option value="">Todos os métodos</option>
                    @foreach (['credit_card' => 'Cartão', 'pix' => 'PIX', 'boleto' => 'Boleto', 'transfer' => 'Transferência', 'cash' => 'Dinheiro', 'other' => 'Outro'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('method') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="date" name="from" value="{{ request('from') }}" title="De">
                <input type="date" name="to" value="{{ request('to') }}" title="Até">
                <button type="submit" class="btn btn--small">Filtrar</button>
                <a href="{{ route('finance.payments.index') }}" class="btn btn--ghost btn--small">Limpar</a>
            </form>
        </div>
    </div>

    @if ($payments->isEmpty())
        <p class="sales-empty">Nenhum pagamento com esses filtros.</p>
    @else
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Cliente</th>
                    <th>Método</th>
                    <th>Origem</th>
                    <th>Cobrança</th>
                    <th>Referência</th>
                    <th>Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at?->format('d/m/Y') }}</td>
                        <td>{{ $payment->prospect?->name ?? '—' }}</td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td>{{ $payment->sourceLabel() }}</td>
                        <td>
                            @if ($payment->invoice)
                                <a href="{{ route('finance.invoices.show', $payment->invoice) }}">{{ $payment->invoice->number }}</a>
                            @else
                                <span class="fin-note">Sem cobrança</span>
                            @endif
                        </td>
                        <td>{{ $payment->external_ref ?: ($payment->reference ?: '—') }}</td>
                        <td><x-money :cents="$payment->amount_cents" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="margin-top:16px;">{{ $payments->links() }}</div>
    @endif
</div>

<script>
    (function () {
        const contract = document.getElementById('paymentContract');
        const amount = document.getElementById('paymentAmount');
        const method = document.getElementById('paymentMethod');
        const prospect = document.getElementById('paymentProspect');

        contract?.addEventListener('change', function () {
            const option = contract.options[contract.selectedIndex];

            if (option?.dataset?.amount) amount.value = option.dataset.amount;
            if (option?.dataset?.method) method.value = option.dataset.method;
            if (option?.dataset?.prospect) prospect.value = option.dataset.prospect;
        });
    })();
</script>
@endsection

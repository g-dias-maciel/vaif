@extends('layouts.app')

@section('title', 'Nova cobrança')

@section('content')
@include('finance.partials.styles')

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">Nova cobrança</h1>
            <p class="meta" style="margin-top:6px;">Cobrança manual para PIX, boleto ou outro meio. O número é gerado automaticamente.</p>
        </div>
        <a href="{{ route('finance.invoices.index') }}" class="btn btn--ghost btn--small">Voltar</a>
    </div>

    @if ($errors->any())
        <div class="sales-errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('finance.invoices.store') }}" class="sales-form" id="invoiceForm">
        @csrf

        <label class="full">
            Contrato (opcional)
            <select name="contract_id" id="contractSelect">
                <option value="">Sem contrato — cobrança avulsa</option>
                @foreach ($contracts as $contract)
                    <option value="{{ $contract->id }}"
                        data-prospect="{{ $contract->prospect_id }}"
                        data-amount="{{ number_format($contract->monthly_value_cents / 100, 2, '.', '') }}"
                        data-description="Plano {{ $contract->planLabel() }}"
                        @selected(old('contract_id', $selected?->id) === $contract->id)>
                        {{ $contract->prospect?->name }} — {{ $contract->planLabel() }} — R$ {{ number_format($contract->monthly_value_cents / 100, 2, ',', '.') }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            Cliente
            <select name="prospect_id" id="prospectSelect" required>
                <option value="">Selecione</option>
                @foreach ($prospects as $prospect)
                    <option value="{{ $prospect->id }}" @selected(old('prospect_id', $selected?->prospect_id) === $prospect->id)>{{ $prospect->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Valor (R$)
            <input type="number" step="0.01" min="0.01" name="amount" id="amountInput" value="{{ old('amount', $selected ? number_format($selected->monthly_value_cents / 100, 2, '.', '') : '') }}" required>
        </label>

        <label>
            Status
            <select name="status">
                <option value="sent" @selected(old('status', 'sent') === 'sent')>Enviada</option>
                <option value="draft" @selected(old('status') === 'draft')>Rascunho</option>
            </select>
        </label>

        <label>
            Forma de pagamento
            <select name="payment_method">
                @foreach (['pix' => 'PIX', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'transfer' => 'Transferência', 'cash' => 'Dinheiro', 'other' => 'Outro'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('payment_method', 'pix') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Emissão
            <input type="date" name="issue_date" value="{{ old('issue_date', $invoice->issue_date?->toDateString()) }}" required>
        </label>

        <label>
            Vencimento
            <input type="date" name="due_date" value="{{ old('due_date', $invoice->due_date?->toDateString()) }}" required>
        </label>

        <label>
            Período — início
            <input type="date" name="period_start" value="{{ old('period_start', $invoice->period_start?->toDateString()) }}" required>
        </label>

        <label>
            Período — fim
            <input type="date" name="period_end" value="{{ old('period_end', $invoice->period_end?->toDateString()) }}" required>
        </label>

        <label class="full">
            Descrição do item
            <input type="text" name="description" id="descriptionInput" value="{{ old('description', 'Cobrança VAIF') }}" required>
        </label>

        <label>
            Referência externa
            <input type="text" name="external_ref" value="{{ old('external_ref') }}" placeholder="ID do Mercado Pago / n8n (opcional)">
        </label>

        <label class="full">
            Observações
            <textarea name="notes">{{ old('notes') }}</textarea>
        </label>

        <div class="full">
            <button type="submit" class="btn">Criar cobrança</button>
        </div>
    </form>
</div>

<script>
    (function () {
        const contract = document.getElementById('contractSelect');
        const prospect = document.getElementById('prospectSelect');
        const amount = document.getElementById('amountInput');
        const description = document.getElementById('descriptionInput');

        contract?.addEventListener('change', function () {
            const option = contract.options[contract.selectedIndex];
            const prospectId = option?.dataset?.prospect || '';
            const value = option?.dataset?.amount || '';
            const label = option?.dataset?.description || '';

            if (prospectId) prospect.value = prospectId;
            if (value && !amount.value) amount.value = value;
            if (label) description.value = label;
        });
    })();
</script>
@endsection

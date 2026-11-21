@extends('layouts.app')

@section('title', $invoice->exists ? 'Editar cobrança' : 'Nova cobrança')

@section('content')
@include('finance.partials.styles')
@php
    $editing = $invoice->exists;
    $fallbackBack = $editing ? route('finance.invoices.show', $invoice) : route('finance.invoices.index');
    $backUrl = \App\Support\FinanceNavigation::backUrl(request('return_to'), $fallbackBack);
    $navMonth = $invoice->period_start ?? now();
@endphp
@include('finance.partials.nav', ['active' => 'invoices', 'navMonth' => $navMonth, 'backUrl' => $backUrl])
@include('finance.partials.breadcrumbs', ['items' => [
    ['label' => $navMonth->format('m/Y'), 'url' => route('finance.months', ['month' => $navMonth->format('Y-m')])],
    ['label' => 'Cobranças', 'url' => route('finance.invoices.index')],
    ['label' => $editing ? $invoice->number : 'Nova cobrança', 'url' => null],
]])

@php
    $itemRows = old('items');

    if ($itemRows === null) {
        if ($editing && $invoice->items->isNotEmpty()) {
            $itemRows = $invoice->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_amount' => number_format($item->unit_amount_cents / 100, 2, '.', ''),
            ])->all();
        } elseif ($selected) {
            $itemRows = [[
                'description' => 'Plano '.$selected->planLabel(),
                'quantity' => 1,
                'unit_amount' => number_format($selected->monthly_value_cents / 100, 2, '.', ''),
            ]];
        } else {
            $itemRows = [['description' => '', 'quantity' => 1, 'unit_amount' => '']];
        }
    }

    $discountValue = old('discount', $invoice->discount_cents ? number_format($invoice->discount_cents / 100, 2, '.', '') : '');
@endphp

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $editing ? 'Editar cobrança '.$invoice->number : 'Nova cobrança' }}</h1>
            <p class="meta" style="margin-top:6px;">
                A fatura entra como <strong>aguardando aprovação</strong>; depois você aprova e envia pelo WhatsApp.
            </p>
        </div>
        <a href="{{ $backUrl }}" class="btn btn--ghost btn--small">Voltar</a>
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

    <form method="post" action="{{ $editing ? route('finance.invoices.update', $invoice) : route('finance.invoices.store') }}" class="sales-form" id="invoiceForm">
        @csrf
        <input type="hidden" name="return_to" value="{{ old('return_to', request('return_to')) }}">
        @if ($editing)
            @method('put')
        @endif

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
                    <option value="{{ $prospect->id }}" @selected(old('prospect_id', $selected?->prospect_id ?? $invoice->prospect_id) === $prospect->id)>{{ $prospect->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Forma de pagamento
            <select name="payment_method">
                @foreach (['pix' => 'PIX', 'credit_card' => 'Cartão', 'boleto' => 'Boleto', 'transfer' => 'Transferência', 'cash' => 'Dinheiro', 'other' => 'Outro'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('payment_method', $invoice->payment_method ?? 'pix') === $value)>{{ $label }}</option>
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

        <div class="full">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">
                <strong style="font-size:.85rem;">Itens da fatura</strong>
                <button type="button" class="btn btn--ghost btn--small" id="addItem">Adicionar item</button>
            </div>

            <div id="items">
                @foreach ($itemRows as $index => $row)
                    <div class="fin-item-row" data-item-row>
                        <label>
                            Descrição
                            <input type="text" name="items[{{ $index }}][description]" value="{{ $row['description'] ?? '' }}" placeholder="Serviço / plano">
                        </label>
                        <label>
                            Qtd.
                            <input type="number" step="0.01" min="0.01" name="items[{{ $index }}][quantity]" value="{{ $row['quantity'] ?? 1 }}" data-qty>
                        </label>
                        <label>
                            Unitário (R$)
                            <input type="number" step="0.01" min="0" name="items[{{ $index }}][unit_amount]" value="{{ $row['unit_amount'] ?? '' }}" data-unit>
                        </label>
                        <button type="button" class="btn btn--ghost btn--small" data-remove-item title="Remover">×</button>
                    </div>
                @endforeach
            </div>
        </div>

        <label>
            Desconto (R$)
            <input type="number" step="0.01" min="0" name="discount" id="discountInput" value="{{ $discountValue }}">
        </label>

        <label>
            Referência externa
            <input type="text" name="external_ref" value="{{ old('external_ref', $invoice->external_ref) }}" placeholder="Opcional">
        </label>

        <div class="full">
            <div class="fin-card" style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>Subtotal: <strong data-subtotal>R$ 0,00</strong></div>
                <div>Desconto: <strong data-discount>R$ 0,00</strong></div>
                <div>Total: <strong data-total>R$ 0,00</strong></div>
            </div>
        </div>

        <label class="full">
            Observações
            <textarea name="notes">{{ old('notes', $invoice->notes) }}</textarea>
        </label>

        <div class="full">
            <p class="fin-note" style="margin-bottom:10px;">
                Ao salvar, a fatura fica aguardando aprovação. O endereço de cobrança vem do cadastro do cliente
                (edite em <strong>Contratos</strong>).
            </p>
            <button type="submit" class="btn">{{ $editing ? 'Salvar fatura' : 'Criar fatura' }}</button>
        </div>
    </form>
</div>

<template id="itemTemplate">
    <div class="fin-item-row" data-item-row>
        <label>
            Descrição
            <input type="text" data-name="description" placeholder="Serviço / plano">
        </label>
        <label>
            Qtd.
            <input type="number" step="0.01" min="0.01" value="1" data-name="quantity" data-qty>
        </label>
        <label>
            Unitário (R$)
            <input type="number" step="0.01" min="0" data-name="unit_amount" data-unit>
        </label>
        <button type="button" class="btn btn--ghost btn--small" data-remove-item title="Remover">×</button>
    </div>
</template>

<script>
    (function () {
        const form = document.getElementById('invoiceForm');
        const items = document.getElementById('items');
        const template = document.getElementById('itemTemplate');
        const discount = document.getElementById('discountInput');
        const subtotalEl = form.querySelector('[data-subtotal]');
        const discountEl = form.querySelector('[data-discount]');
        const totalEl = form.querySelector('[data-total]');
        let index = items.querySelectorAll('[data-item-row]').length;

        function money(cents) {
            return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function renumber() {
            items.querySelectorAll('[data-item-row]').forEach((row, i) => {
                row.querySelectorAll('[data-name]').forEach((input) => {
                    input.name = 'items[' + i + '][' + input.dataset.name + ']';
                });
                row.querySelectorAll('input[type="text"]').forEach((input) => {
                    if (!input.dataset.name) input.name = 'items[' + i + '][description]';
                });
                row.querySelectorAll('[data-qty]').forEach((input) => {
                    if (!input.dataset.name) input.name = 'items[' + i + '][quantity]';
                });
                row.querySelectorAll('[data-unit]').forEach((input) => {
                    if (!input.dataset.name) input.name = 'items[' + i + '][unit_amount]';
                });
            });
        }

        function recalc() {
            let subtotal = 0;

            items.querySelectorAll('[data-item-row]').forEach((row) => {
                const qty = parseFloat(row.querySelector('[data-qty]')?.value || '0') || 0;
                const unit = Math.round((parseFloat(row.querySelector('[data-unit]')?.value || '0') || 0) * 100);
                subtotal += Math.round(unit * qty);
            });

            const discountCents = Math.round((parseFloat(discount?.value || '0') || 0) * 100);
            const total = Math.max(0, subtotal - discountCents);

            if (subtotalEl) subtotalEl.textContent = money(subtotal);
            if (discountEl) discountEl.textContent = money(discountCents);
            if (totalEl) totalEl.textContent = money(total);
        }

        function bind(row) {
            row.querySelector('[data-remove-item]')?.addEventListener('click', () => {
                if (items.querySelectorAll('[data-item-row]').length <= 1) {
                    row.querySelectorAll('input').forEach((input) => { if (input.type !== 'number') input.value = ''; });
                } else {
                    row.remove();
                }
                renumber();
                recalc();
            });

            row.querySelectorAll('[data-qty], [data-unit]').forEach((input) => {
                input.addEventListener('input', recalc);
            });
        }

        document.getElementById('addItem')?.addEventListener('click', () => {
            const clone = template.content.firstElementChild.cloneNode(true);
            items.appendChild(clone);
            bind(clone);
            renumber();
            recalc();
        });

        items.querySelectorAll('[data-item-row]').forEach(bind);
        discount?.addEventListener('input', recalc);
        renumber();
        recalc();

        document.getElementById('contractSelect')?.addEventListener('change', function () {
            const option = this.options[this.selectedIndex];
            const prospectId = option?.dataset?.prospect || '';
            const amount = option?.dataset?.amount || '';
            const description = option?.dataset?.description || '';
            const prospect = document.getElementById('prospectSelect');
            const first = items.querySelector('[data-item-row]');

            if (prospect && prospectId) prospect.value = prospectId;
            if (first && description) {
                const desc = first.querySelector('input[type="text"]');
                const unit = first.querySelector('[data-unit]');
                if (desc && !desc.value) desc.value = description;
                if (unit && amount && !unit.value) unit.value = amount;
            }
            recalc();
        });
    })();
</script>
@endsection

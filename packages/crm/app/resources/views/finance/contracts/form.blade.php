@extends('layouts.app')

@section('title', $contract->exists ? 'Editar contrato' : 'Vincular contrato')

@section('content')
@include('finance.partials.styles')

@php
    $editing = $contract->exists;
    $monthly = old('monthly_value', $contract->monthly_value_cents !== null && $contract->monthly_value_cents > 0
        ? number_format($contract->monthly_value_cents / 100, 2, '.', '')
        : '');
    $total = old('total_value', $contract->total_value_cents !== null && $contract->total_value_cents > 0
        ? number_format($contract->total_value_cents / 100, 2, '.', '')
        : '');
@endphp

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px;">
        <div>
            <h1 style="margin:0;">{{ $editing ? 'Editar contrato' : 'Vincular contrato' }}</h1>
            <p class="meta" style="margin-top:6px;">
                {{ $prospect->name }} · {{ $prospect->displayPhone() }}
                @if ($prospect->artist)
                    · Artista {{ $prospect->artist->name }}
                @endif
            </p>
        </div>
        <div class="fin-actions">
            <a href="{{ route('finance.clients') }}" class="btn btn--ghost btn--small">Voltar</a>
            @if ($editing && $contract->prospect)
                <a href="{{ route('sales.prospects.show', $prospect) }}" class="btn btn--ghost btn--small">Prospect</a>
            @endif
        </div>
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

    <form method="post" action="{{ $editing ? route('finance.contracts.update', $contract) : route('finance.contracts.store', $prospect) }}" class="sales-form">
        @csrf
        @if ($editing)
            @method('put')
            <input type="hidden" name="prospect_id" value="{{ $prospect->id }}">
        @endif

        <label>
            Plano
            <select name="plan">
                @foreach ([
                    'mensal' => 'Mensal — R$ 3.250',
                    'semestral' => 'Semestral — R$ 1.800',
                    'twelve_months' => '12 meses — R$ 1.500',
                    'full_service' => 'Full service — R$ 15.000',
                    'members_area' => '697 — Área de membros',
                ] as $value => $label)
                    <option value="{{ $value }}" @selected(old('plan', $contract->plan) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Status
            <select name="status">
                @foreach (['draft' => 'Rascunho', 'active' => 'Ativo', 'ended' => 'Encerrado', 'churned' => 'Churn'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $contract->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Valor mensal (R$)
            <input type="number" step="0.01" min="0" name="monthly_value" value="{{ $monthly }}">
        </label>

        <label>
            Valor total (R$)
            <input type="number" step="0.01" min="0" name="total_value" value="{{ $total }}" placeholder="Calculado pelo plano se vazio">
        </label>

        <label>
            Início
            <input type="date" name="start_date" value="{{ old('start_date', $contract->start_date?->toDateString() ?? $today) }}">
        </label>

        <label>
            Fim (opcional)
            <input type="date" name="end_date" value="{{ old('end_date', $contract->end_date?->toDateString()) }}">
        </label>

        <label>
            Dia de cobrança
            <input type="number" min="1" max="31" name="billing_day" value="{{ old('billing_day', $contract->billing_day ?? 10) }}">
        </label>

        <label>
            Assinado em
            <input type="date" name="signed_at" value="{{ old('signed_at', $contract->signed_at?->toDateString() ?? $today) }}">
        </label>

        <label>
            Modo de cobrança
            <select name="billing_mode">
                <option value="pix" @selected(old('billing_mode', $contract->billing_mode ?? 'pix') === 'pix')>PIX — gera cobrança</option>
                <option value="card" @selected(old('billing_mode', $contract->billing_mode ?? 'pix') === 'card')>Cartão — Mercado Pago recorrente</option>
            </select>
        </label>

        <label>
            Forma de pagamento
            <select name="payment_method">
                @foreach (['pix' => 'PIX', 'credit_card' => 'Cartão de crédito', 'boleto' => 'Boleto', 'transfer' => 'Transferência', 'cash' => 'Dinheiro', 'other' => 'Outro'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('payment_method', $contract->payment_method ?? 'pix') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Próxima cobrança PIX
            <input type="date" name="next_invoice_at" value="{{ old('next_invoice_at', $contract->next_invoice_at?->toDateString()) }}">
        </label>

        <label>
            Próxima cobrança no cartão
            <input type="date" name="next_charge_at" value="{{ old('next_charge_at', $contract->next_charge_at?->toDateString()) }}">
        </label>

        <label>
            Mercado Pago — preapproval ID
            <input type="text" name="mercado_pago_preapproval_id" value="{{ old('mercado_pago_preapproval_id', $contract->mercado_pago_preapproval_id) }}" placeholder="Opcional">
        </label>

        <label>
            Mercado Pago — customer ID
            <input type="text" name="mercado_pago_customer_id" value="{{ old('mercado_pago_customer_id', $contract->mercado_pago_customer_id) }}" placeholder="Opcional">
        </label>

        <label>
            Notion page ID
            <input type="text" name="notion_page_id" value="{{ old('notion_page_id', $contract->notion_page_id) }}" placeholder="Opcional">
        </label>

        <label class="full">
            Observações
            <textarea name="notes">{{ old('notes', $contract->notes) }}</textarea>
        </label>

        <div class="full">
            <p class="fin-note" style="margin-bottom:10px;">
                O contrato não cria login, convite ou tarefa de onboarding. Para o 697, o valor mensal é zerado e o total deve ser R$ 697.
                A cobrança PIX é gerada pelo comando diário quando a data chega; o cartão é registrado como pagamento.
                <strong>Para clientes já existentes, informe a próxima cobrança em aberto</strong> — assim o sistema não gera meses retroativos.
            </p>
            <button type="submit" class="btn">{{ $editing ? 'Salvar contrato' : 'Criar contrato' }}</button>
        </div>
    </form>
</div>
@endsection
